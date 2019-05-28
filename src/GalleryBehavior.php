<?php

namespace bscheshirwork\yii2\galleryManager;

use Imagine\Image\Box;
use Imagine\Image\ImageInterface;
use Yii;
use yii\base\Behavior;
use yii\base\Exception;
use yii\db\ActiveRecord;
use yii\db\Query;
use yii\helpers\FileHelper;
use yii\helpers\Json;
use yii\imagine\Image;

/**
 * Behavior for adding gallery to any model.
 *
 * @author Bogdan Savluk <savluk.bogdan@gmail.com>
 * @author Bogdan Stepanenko <bscheshir.work@gmail.com>
 *
 * @property string $galleryId
 * @property string $temporaryId
 */
class GalleryBehavior extends Behavior
{
    /**
     * Glue used to implode composite primary keys
     * @var string
     */
    public $pkGlue = '_';
    /**
     * The prefix of string for temporary id for new models
     * @var string
     */
    public $temporaryPrefix = 'temp';
    /**
     * The index of temporary id for new models. Can be separate multiple gallery by index like widgetId or increment integer
     * @var string
     */
    public $temporaryIndex = '0';
    /**
     * The rule for create temporary id.
     * Available placeholders:
     *    '{temporaryPrefix}' => $this->temporaryPrefix,
     *    '{temporaryIndex}' => $this->temporaryIndex,
     *    '{sessionId}' => Yii::$app->session->getId(),
     *    '{userId}' => Yii::$app->user->getId(),
     *    '{combineId}' => Yii::$app->user->getId() ?? Yii::$app->session->getId(),
     * @var string
     */
    public $temporaryTemplate = '{temporaryPrefix}-{temporaryIndex}-{combineId}';
    /**
     * The rule for read temporary id. Can be a valid regexp part.
     * @see GalleryBehavior::getTemporaryId()
     * {temporaryIndex} will be replaced by $temporaryIndexFilter:
     * For example '/{temporaryPrefix}-(\d+)-{sessionId}/'
     * another placeholders will be replaced like $temporaryTemplate available placeholders
     * $temporaryIndex will be read from $matches[1] from result regexp
     * @var string
     */
    public $temporaryIndexFilter = '(\d+)';
    /**
     * @var string Type name assigned to model in image attachment action
     * @see     GalleryManagerAction::$types
     * @example $type = 'Post' where 'Post' is the model name
     */
    public $type;
    /**
     * @var ActiveRecord the owner of this behavior
     * @example $owner = Post where Post is the ActiveRecord with GalleryBehavior attached under public function behaviors()
     */
    public $owner;
    /**
     * Widget preview height
     * @var int
     */
    public $previewHeight = 88;
    /**
     * Widget preview width
     * @var int
     */
    public $previewWidth = 130;
    /**
     * Extension for saved images
     * @var string
     */
    public $extension;
    /**
     * Path to directory where to save uploaded images
     * @var string
     */
    public $directory;
    /**
     * Directory Url, without trailing slash
     * @var string
     */
    public $url;
    /**
     * Path to directory where to save uploaded images for imaginary. This is shortcut to binding dir
     *     volumes:
     *       - ../code/php/storage/web:/mnt/data
     *
     * 'directory' => Yii::getAlias('@storageWeb') . ($galleryPath = '/images/gallery/o'),
     * 'url' => Yii::getAlias('@storageUrl') . $galleryPath,
     * 'imaginaryDirectory' => $galleryPath,
     *
     * @var string
     */
    public $imaginaryDirectory;
    /**
     * @var string imaginary url for calling
     */
    public $imaginary = 'http://imaginary:9000';
    /**
     * @var array Functions to generate image versions
     * @note Be sure to not modify image passed to your version function,
     *       because it will be reused in all other versions,
     *       Before modification you should copy images as in examples below
     * @note 'preview' & 'original' versions names are reserved for image preview in widget
     *       and original image files, if it is required - you can override them
     * @example
     * [
     *  'small' => function ($originalImagePath, $originalImagePathForImagine) {
     *      return $img
     *          ->copy()
     *          ->resize($img->getSize()->widen(200));
     *  },
     * ]
     */
    public $versions;
    /**
     * name of query param for modification time hash
     * to avoid using outdated version from cache - set it to false
     * @var string
     */
    public $timeHash = '_';

    /**
     * Used by GalleryManager
     * @var bool
     * @see GalleryManager::run
     */
    public $hasName = true;
    /**
     * Used by GalleryManager
     * @var bool
     * @see GalleryManager::run
     */
    public $hasDescription = true;

    /**
     * @var string Table name for saving gallery images meta information
     */
    public $tableName = '{{%gallery_image}}';
    protected $_galleryId;

    /**
     * @param ActiveRecord $owner
     */
    public function attach($owner)
    {
        parent::attach($owner);
        if (!isset($this->versions['original'])) {
            $this->versions['original'] = function ($originalImagePath, $originalImagePathForImagine) {
                return file_get_contents($originalImagePath);
            };
        }
        if (!isset($this->versions['preview'])) {
            $this->versions['preview'] = function ($originalImagePath, $originalImagePathForImagine) {
                $httpQuery = http_build_query([
                    'file' => $originalImagePathForImagine,
                    'width' => $this->previewWidth,
                    'height' => $this->previewHeight,
                ]);

                return file_get_contents($this->imaginary . '/crop?' . $httpQuery);
            };
        }
    }

    public function events()
    {
        return [
            ActiveRecord::EVENT_BEFORE_DELETE => 'beforeDelete',
            ActiveRecord::EVENT_AFTER_UPDATE => 'afterUpdate',
            ActiveRecord::EVENT_AFTER_FIND => 'afterFind',
            ActiveRecord::EVENT_BEFORE_INSERT => 'afterFind',
            ActiveRecord::EVENT_AFTER_INSERT => 'afterInsert',
        ];
    }

    public function beforeDelete()
    {
        $images = $this->getImages();
        foreach ($images as $image) {
            $this->deleteImage($image->id);
        }
        $this->removeDirectory($this->getDirectoryPath());
    }

    public function afterFind()
    {
        $this->_galleryId = $this->getGalleryId();
    }

    public function afterUpdate()
    {
        $galleryId = $this->getGalleryId();
        if ($this->_galleryId && ($this->_galleryId != $galleryId)) {
            $dirPath1 = $this->directory . '/' . $this->_galleryId;
            $dirPath2 = $this->directory . '/' . $galleryId;
            if (is_dir($dirPath1)) {
                rename($dirPath1, $dirPath2);
            }
        }
    }

    /**
     * Move dir and change id to actual
     * @throws Exception
     * @throws \yii\db\Exception
     */
    public function afterInsert()
    {
        $galleryId = $this->getGalleryId();
        if ($this->_galleryId && ($this->_galleryId != $galleryId)) {

            \Yii::$app->db->createCommand()
                ->update(
                    $this->tableName,
                    ['ownerId' => $galleryId],
                    ['ownerId' => $this->_galleryId, 'type' => $this->type]
                )->execute();

            $dirPath1 = $this->directory . '/' . $this->_galleryId;
            $dirPath2 = $this->directory . '/' . $galleryId;
            if (is_dir($dirPath1)) {
                rename($dirPath1, $dirPath2);
            }
        }
    }

    /**
     * Rollback renaming of dir. Execute it before call transaction rollback:
     *
     *         $transaction = \Yii::$app->db->beginTransaction();
     *         $transactionLevel = $transaction->level;
     *         $isNewRecord = $this->_model->isNewRecord;
     *         try {
     *             if ($pass = $this->_model->save()) {
     *                 // ... save another related AR
     *             }
     *             if ($pass) {
     *                 $transaction->commit();
     *
     *                 return true;
     *             } else {
     *                 $transaction->rollBack();
     *                 $this->_phones->deleteSuccess = false; // some actions after rollback
     *                 // rename back dir of gallery before transaction rollback
     *                 $behavior = $this->_model->getBehavior('galleryBehavior');
     *                 $behavior->rollBackDir();
     *                 // back id to null after restore gallery (order is important)
     *                 if ($isNewRecord) {
     *                     $this->_model->isNewRecord = true;
     *                     $this->_model->id = null;
     *                 }
     *             }
     *         } catch (\Exception $e) {
     *             if ($transaction->isActive && $transactionLevel == $transaction->level) {
     *                 $transaction->rollBack();
     *             }
     *             throw $e;
     *         } catch (\Throwable $e) {
     *             if ($transaction->isActive && $transactionLevel == $transaction->level) {
     *                 $transaction->rollBack();
     *             }
     *             throw $e;
     *         }
     *
     * @throws Exception
     */
    public function rollBackDir()
    {
        $galleryId = $this->getGalleryId();
        if ($this->_galleryId && ($this->_galleryId != $galleryId)) {
            $dirPath1 = $this->directory . '/' . $galleryId;
            $dirPath2 = $this->directory . '/' . $this->_galleryId;
            if (is_dir($dirPath1)) {
                rename($dirPath1, $dirPath2);
            }
        }
    }

    protected $_images = null;

    /**
     * @return GalleryImage[]
     * @throws Exception
     */
    public function getImages()
    {
        if ($this->_images === null) {
            $query = new \yii\db\Query();

            $imagesData = $query
                ->select(['id', 'name', 'description', 'rank'])
                ->from($this->tableName)
                ->where(['type' => $this->type, 'ownerId' => $this->getGalleryId()])
                ->orderBy(['rank' => 'asc'])
                ->all();

            $this->_images = [];
            foreach ($imagesData as $imageData) {
                $this->_images[] = new GalleryImage($this, $imageData);
            }
        }

        return $this->_images;
    }

    protected function getFileName($imageId, $version = 'original')
    {
        return implode(
            '/',
            [
                $this->getGalleryId(),
                $imageId,
                $version . '.' . $this->extension,
            ]
        );
    }

    public function getUrl($imageId, $version = 'original')
    {
        $path = $this->getFilePath($imageId, $version);

        if (!file_exists($path)) {
            return null;
        }

        if (!empty($this->timeHash)) {

            $time = filemtime($path);
            $suffix = '?' . $this->timeHash . '=' . crc32($time);
        } else {
            $suffix = '';
        }

        return $this->url . '/' . $this->getFileName($imageId, $version) . $suffix;
    }

    public function getFilePath($imageId, $version = 'original')
    {
        return $this->directory . '/' . $this->getFileName($imageId, $version);
    }

    public function getImaginaryFilePath($imageId, $version = 'original')
    {
        return $this->imaginaryDirectory . '/' . $this->getFileName($imageId, $version);
    }

    public function getDirectoryPath()
    {
        return $this->directory . '/' . $this->getGalleryId();
    }

    /**
     * Get application components if necessary
     */
    public function getApplicationDataByTemplate()
    {
        $result = [];
        if (Yii::$app instanceof \yii\web\Application) {
            if (strpos($this->temporaryTemplate, '{userId}') !== false) {
                $result['{userId}'] = Yii::$app->user->getId();
            }
            if (strpos($this->temporaryTemplate, '{sessionId}') !== false) {
                $result['{sessionId}'] = Yii::$app->session->getId();
            }
            if (strpos($this->temporaryTemplate, '{combineId}') !== false) {
                $result['{combineId}'] = Yii::$app->user->getId() ?? Yii::$app->session->getId();
            }
        }

        return $result;
    }

    /**
     * Build temporaryIndex from dirty galleryId
     *
     * @param $rawGalleryId
     * @return bool
     */
    public function setTemporaryId($rawGalleryId)
    {
        $template = strtr($this->temporaryTemplate, $this->getApplicationDataByTemplate());
        $regexp = '/' . strtr($template, [
                '{temporaryPrefix}' => $this->temporaryPrefix,
                '{temporaryIndex}' => $this->temporaryIndexFilter,
            ]) . '/';
        if (preg_match($regexp, $rawGalleryId, $matches) !== FALSE) {
            $this->temporaryIndex = $matches[1];

            return true;
        }

        return false;
    }

    /**
     * Generate a temporary id for new models
     * @return string
     */
    public function getTemporaryId()
    {
        $template = strtr($this->temporaryTemplate, $this->getApplicationDataByTemplate());

        return strtr($template, [
            '{temporaryPrefix}' => $this->temporaryPrefix,
            '{temporaryIndex}' => $this->temporaryIndex,
        ]);
    }

    /**
     * Get Gallery Id
     *
     * @return mixed as string or integer
     * @throws Exception
     */
    public function getGalleryId()
    {
        $pk = $this->owner->getPrimaryKey();
        if ($pk === null) {
            $pk = $this->getTemporaryId();
        }
        if (is_array($pk)) {
            return implode($this->pkGlue, $pk);
        } else {
            return $pk;
        }
    }

    /**
     * Replace existing image by specified file
     *
     * @param $imageId
     * @param $path
     */
    public function replaceImage($imageId, $path)
    {
        $onlyVersions = null;

        $originalImagePath = $this->getFilePath($imageId, 'original');
        $originalImagePathForImagine = $this->getImaginaryFilePath($imageId, 'original');
        $this->createFolders($originalImagePath);
        file_put_contents($originalImagePath, file_get_contents($path)); // move file. rename is not work
        $image = $this->versions['original']($originalImagePath, $originalImagePathForImagine);
        file_put_contents($originalImagePath, $image);

        foreach ($this->versions as $version => $fn) {
            if ($version !== 'original' && ($onlyVersions === null || array_search($version, (array) $onlyVersions) !== false)) {
                $image = $fn($originalImagePath, $originalImagePathForImagine);
                file_put_contents($this->getFilePath($imageId, $version), $image);
            }
        }
    }

    /**
     * Remove single image file
     * @param $fileName
     * @return bool
     */
    private function removeFile($fileName)
    {
        try {
            return FileHelper::unlink($fileName);
        } catch (\yii\base\ErrorException $exception) {
            return false;
        }
    }

    /**
     * Remove a folders for gallery files
     * @param $dirPath string the dirname of image
     * @return bool
     */
    private function removeDirectory($dirPath)
    {
        try {
            FileHelper::removeDirectory($dirPath);
        } catch (\yii\base\ErrorException $exception) {
            return false;
        }

        return true;
    }

    /**
     * Create a folders for gallery files
     * @param $filePath string the filename of image
     * @return bool
     */
    private function createFolders($filePath)
    {
        try {
            return FileHelper::createDirectory(FileHelper::normalizePath(dirname($filePath)), 0777);
        } catch (\yii\base\Exception $exception) {
            return false;
        }
    }

    /////////////////////////////// ========== Public Actions ============ ///////////////////////////

    /**
     * @param $imageId
     * @return bool
     * @throws Exception
     * @throws \yii\db\Exception
     */
    public function deleteImage($imageId)
    {
        $db = \Yii::$app->db;
        $result = (bool) $db->createCommand()
            ->delete(
                $this->tableName,
                [
                    'type' => $this->type,
                    'ownerId' => $this->getGalleryId(),
                    'id' => $imageId
                ]
            )->execute();

        if ($result) {
            foreach ($this->versions as $version => $fn) {
                $filePath = $this->getFilePath($imageId, $version);
                $this->removeFile($filePath);
            }
            $filePath = $this->getFilePath($imageId, 'original');
            $this->removeDirectory(dirname($filePath));
        }

        return $result;
    }

    /**
     * Delete images by id list.
     * If _images is set after delete all of stored images the directory will be delete too.
     * @param $imageIds
     * @throws Exception
     * @throws \yii\db\Exception
     */
    public function deleteImages($imageIds)
    {
        foreach ($imageIds as $imageId) {
            $this->deleteImage($imageId);
        }
        if ($this->_images !== null) {
            $removed = array_combine($imageIds, $imageIds);
            $this->_images = array_filter(
                $this->_images,
                function ($image) use (&$removed) {
                    return !isset($removed[$image->id]);
                }
            );
            if (empty($this->_images)) {
                $this->removeDirectory($this->getDirectoryPath());
            }
        }
    }

    /**
     * Remove images for expired session
     * actions is a {'apiRoute'}?action=deleteOrphan&type={'type'}&behaviorName={'behaviorName'}
     * @throws \yii\base\ErrorException
     * @throws \yii\db\Exception
     */
    public function deleteOrphanImages()
    {
        $toDelete = \Yii::$app->db->createCommand(
            'SELECT DISTINCT `ownerId` FROM ' . $this->tableName . ' WHERE `ownerId` LIKE :ownerId AND `type` = :type',
            [':ownerId' => $this->temporaryPrefix . '%', ':type' => $this->type]
        )->queryColumn();

        foreach ($toDelete as $item) {
            \Yii::$app->db->createCommand()
                ->delete(
                    $this->tableName,
                    [
                        'type' => $this->type,
                        'ownerId' => $item,
                    ]
                )->execute();
            FileHelper::removeDirectory($this->directory . '/' . $item);
        }
    }

    /**
     * Add image to a gallery table.
     * Replace filename of image to common form with galleryId
     * @param $fileName
     * @return GalleryImage
     * @throws Exception
     * @throws \yii\db\Exception
     */
    public function addImage($fileName)
    {
        $db = \Yii::$app->db;
        $db->createCommand()
            ->insert(
                $this->tableName,
                [
                    'type' => $this->type,
                    'ownerId' => $this->getGalleryId()
                ]
            )->execute();

        $id = $db->getLastInsertID('gallery_image_id_seq');
        $db->createCommand()
            ->update(
                $this->tableName,
                ['rank' => $id],
                ['id' => $id]
            )->execute();

        $this->replaceImage($id, $fileName);

        $galleryImage = new GalleryImage($this, ['id' => $id, 'rank' => $id]);

        if ($this->_images !== null) {
            $this->_images[] = $galleryImage;
        }

        return $galleryImage;
    }

    /**
     * Change order of images
     * @param $order
     * @return mixed
     * @throws \yii\db\Exception
     */
    public function arrange($order)
    {
        $orders = [];
        $i = 0;
        foreach ($order as $k => $v) {
            if (!$v) {
                $order[$k] = $k;
            }
            $orders[] = $order[$k];
            $i++;
        }
        sort($orders);
        $i = 0;
        $res = [];
        foreach ($order as $k => $v) {
            $res[$k] = $orders[$i];

            \Yii::$app->db->createCommand()
                ->update(
                    $this->tableName,
                    ['rank' => $orders[$i]],
                    ['id' => $k]
                )->execute();

            $i++;
        }

        // todo: arrange images if presented
        return $order;
    }

    /**
     * Update name and descriptions of images
     * @param array $imagesData
     *
     * @return GalleryImage[]
     * @throws Exception
     * @throws \yii\db\Exception
     */
    public function updateImagesData($imagesData)
    {
        $imageIds = array_keys($imagesData);
        $imagesToUpdate = [];
        if ($this->_images !== null) {
            $selected = array_combine($imageIds, $imageIds);
            foreach ($this->_images as $img) {
                if (isset($selected[$img->id])) {
                    $imagesToUpdate[] = $selected[$img->id];
                }
            }
        } else {
            $rawImages = (new Query())
                ->select(['id', 'name', 'description', 'rank'])
                ->from($this->tableName)
                ->where(['type' => $this->type, 'ownerId' => $this->getGalleryId()])
                ->andWhere(['in', 'id', $imageIds])
                ->orderBy(['rank' => 'asc'])
                ->all();
            foreach ($rawImages as $image) {
                $imagesToUpdate[] = new GalleryImage($this, $image);
            }
        }


        foreach ($imagesToUpdate as $image) {
            if (isset($imagesData[$image->id]['name'])) {
                $image->name = $imagesData[$image->id]['name'];
            }
            if (isset($imagesData[$image->id]['description'])) {
                $image->description = $imagesData[$image->id]['description'];
            }
            \Yii::$app->db->createCommand()
                ->update(
                    $this->tableName,
                    ['name' => $image->name, 'description' => $image->description],
                    ['id' => $image->id]
                )->execute();
        }

        return $imagesToUpdate;
    }

    /**
     * Regenerate image versions
     * Should be called in migration on every model after changes in versions configuration
     *
     * @param string|null $oldExtension
     * @param string[]|null $onlyVersions process only versions
     * @return array [$success, $fail] the counts of processed images
     * @throws Exception
     */
    public function updateImages($oldExtension = null, $onlyVersions = null)
    {
        $success = 0;
        $fail = 0;
        foreach ($this->getImages() as ['id' => $imageId]) {
            if ($oldExtension !== null) {
                $newExtension = $this->extension;
                $this->extension = $oldExtension;
                try {
                    //old extension in id
                    $originalImagePath = $this->getFilePath($imageId, 'original');
                    $originalImagePathForImagine = $this->getImaginaryFilePath($imageId, 'original');
                    $image = $this->versions['original']($originalImagePath, $originalImagePathForImagine);
                } catch (\Exception $exception) {
                    $fail++;
                    Yii::getLogger()->log($exception->getMessage(), \yii\log\Logger::LEVEL_WARNING, __METHOD__);
                    continue;
                }
                foreach ($this->versions as $version => $fn) {
                    $this->removeFile($this->getFilePath($imageId, $version));
                }
                //new extension in id
                $this->extension = $newExtension;
                $originalImagePath = $this->getFilePath($imageId, 'original');
                $originalImagePathForImagine = $this->getImaginaryFilePath($imageId, 'original');
                file_put_contents($originalImagePath, $image);
            } else {
                try {
                    $originalImagePath = $this->getFilePath($imageId, 'original');
                    $originalImagePathForImagine = $this->getImaginaryFilePath($imageId, 'original');
                    $this->createFolders($originalImagePath);
                    $image = $this->versions['original']($originalImagePath, $originalImagePathForImagine);
                    unset($image);
                } catch (\Exception $exception) {
                    $fail++;
                    Yii::getLogger()->log($exception->getMessage(), \yii\log\Logger::LEVEL_WARNING, __METHOD__);
                    continue;
                }
            }

            foreach ($this->versions as $version => $fn) {
                if ($version !== 'original' && ($onlyVersions === null || array_search($version, (array) $onlyVersions) !== false)) {
                    try {
                        $image = $fn($originalImagePath, $originalImagePathForImagine);
                        file_put_contents($this->getFilePath($imageId, $version), $image);
                        $success++;
                    } catch (\Exception $exception) {
                        Yii::getLogger()->log($exception->getMessage(), \yii\log\Logger::LEVEL_WARNING, __METHOD__);
                        $fail++;
                    }
                }
            }
        }

        return [$success, $fail];
    }
}
