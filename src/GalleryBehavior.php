<?php

namespace bscheshirwork\yii2\galleryManager;

use Yii;
use yii\base\Behavior;
use yii\base\Exception;
use yii\db\ActiveRecord;
use yii\helpers\FileHelper;

/**
 * Behavior for adding gallery to any model.
 *
 * @author Bogdan Savluk <savluk.bogdan@gmail.com>
 * @author Bogdan Stepanenko <bscheshir.work@gmail.com>
 *
 * @property string $galleryId
 */
class GalleryBehavior extends Behavior
{
    /**
     * Glue used to implode composite primary keys
     * @var string
     */
    public $pkGlue = '_';
    /**
     * The index of temporary id for new models. Can be separate multiple gallery by index like widgetId or increment integer
     * @var string
     */
    public $temporaryIndex = '0';
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
     * directory fol all temporary images.
     * Instead of ownerId.
     * Image will be separated by id same at common case: directory/tempDirectory/imageId/version.jpg
     * @var string
     */
    public $tempDirectory = 'temp';
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
     * Used by GalleryManager for frontend js
     * @var bool
     * @see GalleryManager::run
     */
    public $hasName = true;
    /**
     * Used by GalleryManager for frontend js
     * @var bool
     * @see GalleryManager::run
     */
    public $hasDescription = true;

    /**
     * AR for saving gallery images meta information
     * Can be redefine for your own class with different table
     * @var string|array
     */
    public $imageClass = GalleryImage::class;

    /**
     * AR for saving temporary id for new record information
     * Can be redefine for your own class with different table
     * @var string|array
     */
    public $tempClass = GalleryTemp::class;

    /**
     * @var string currently folder of images
     */
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

    /**
     * {@inheritDoc}
     * @return array
     */
    public function events()
    {
        return [
            ActiveRecord::EVENT_BEFORE_DELETE => 'beforeDelete',
            ActiveRecord::EVENT_AFTER_UPDATE => 'afterUpdate',
            ActiveRecord::EVENT_AFTER_FIND => 'afterFind',
            ActiveRecord::EVENT_AFTER_INSERT => 'afterInsert',
        ];
    }

    private $_lastEvent;

    public function beforeDelete()
    {
        $images = $this->getImages();
        foreach ($images as $image) {
            $this->deleteImage($image->id);
        }
        $this->removeDirectory($this->getDirectoryPath());
        $this->_lastEvent = 'beforeDelete';
    }

    public function afterFind()
    {
        $this->_galleryId = $this->getGalleryId();
        $this->_lastEvent = 'afterFind';
    }

    /**
     * Move dir to another id if related id will be change.
     * Only for find -> update. Not for insert -> update.
     * @throws Exception
     */
    public function afterUpdate()
    {
        if ($this->_lastEvent == 'afterFind') {
            $galleryId = $this->getGalleryId();
            if ($this->_galleryId && ($this->_galleryId != $galleryId)) {
                $dirPath1 = $this->directory . '/' . $this->_galleryId;
                $dirPath2 = $this->directory . '/' . $galleryId;
                if (is_dir($dirPath1)) {
                    rename($dirPath1, $dirPath2);
                }
            }
        }
        $this->_lastEvent = 'afterUpdate';
    }

    /**
     * Move dir and change id to actual
     * @throws Exception
     * @throws \yii\db\Exception
     */
    public function afterInsert()
    {
        $galleryId = $this->getGalleryId();
        // We have fill form in this request
        // not for another case insert.
        /** @var GalleryTemp $tempInstance */
        $tempInstance = Yii::createObject($this->tempClass);
        $imageIds = $tempInstance::imageIdsFromTemp($this->temporaryIndex);
        if ($imageIds) {
            /** @var GalleryImage $instance */
            $instance = Yii::createObject($this->imageClass);
            $instance::updateAll(['ownerId' => $galleryId], ['id' => $imageIds, 'type' => $this->type]);
            $this->_galleryId = $this->tempDirectory;
            foreach ($imageIds as $imageId) {
                //rename only related ids
                $dirPath1 = $this->directory . DIRECTORY_SEPARATOR . $this->_galleryId . DIRECTORY_SEPARATOR . $imageId;
                $dirPath2 = $this->directory . DIRECTORY_SEPARATOR . $galleryId . DIRECTORY_SEPARATOR . $imageId;
                if (is_dir($dirPath1)) {
                    $this->createFolders($dirPath2);
                    rename($dirPath1, $dirPath2);
                }
            }
        }
        $this->_lastEvent = 'afterInsert';
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
            $galleryDir = $this->directory . DIRECTORY_SEPARATOR . $galleryId;
            if (is_dir($galleryDir)) {
                $dirs = FileHelper::findDirectories($galleryDir, ['recursive' => false]);
                foreach ($dirs as $dirPath1) {
                    $basename = basename($dirPath1);
                    //rename only related ids
                    $dirPath2 = $this->directory . DIRECTORY_SEPARATOR . $this->_galleryId . DIRECTORY_SEPARATOR . $basename;
                    if (is_dir($dirPath1)) {
                        $this->createFolders($dirPath2);
                        rename($dirPath1, $dirPath2);
                    }
                }
                $this->removeDirectory($galleryDir);
            }
        }
    }

    /**
     * Remove temporary id (this id will use for load image on new model)
     * This data will be used on self::rollbackDir
     * Run this on finally save complex form after transaction->commit()
     *
     *  $transaction->commit();
     *  $model->removeGalleryTempData();
     *  foreach ($model->relatedModels as $relatedModel) {
     *      $relatedModel->removeGalleryTempData();
     *  }
     *
     * @throws \yii\base\InvalidConfigException
     * @throws Exception
     */
    public function removeGalleryTempData()
    {
        $imageIds = [];
        /** @var GalleryImage $image */
        foreach ($this->getImages() as $image) {
            $imageIds[] = $image->id;
        }
        if ($imageIds) {
            /** @var GalleryTemp $instanceTemp */
            $instanceTemp = Yii::createObject(GalleryTemp::class);
            $instanceTemp::deleteAll(['imageId' => $imageIds]);
        }
    }

    /**
     * Internal storage for image AR
     * @var null|GalleryImage[]
     */
    protected $_images = null;

    /**
     * @return GalleryImage[]
     * @throws Exception
     */
    public function getImages()
    {
        if ($this->_images === null) {
            /** @var GalleryImage $instance */
            $instance = Yii::createObject(GalleryImage::class);
            if ($ownerId = $this->getGalleryId()) {
                $this->_images = $instance::find()
                    ->where([
                        'type' => $this->type,
                        'ownerId' => $this->getGalleryId(),
                    ])
                    ->orderBy(['rank' => 'asc'])
                    ->all();
                foreach ($this->_images as $image) {
                    $image->galleryBehavior = $this;
                }
            } else {
                /** @var GalleryTemp $instanceTemp */
                $instanceTemp = Yii::createObject(GalleryTemp::class);
                if ($imageIds = $instanceTemp::imageIdsFromTemp($this->temporaryIndex)) {
                    $this->_images = $instance::find()
                        ->where([
                            'id' => $imageIds,
                            'type' => $this->type,
                        ])
                        ->orderBy(['rank' => 'asc'])
                        ->all();
                    foreach ($this->_images as $image) {
                        $image->galleryBehavior = $this;
                    }
                } else {
                    $this->_images = [];
                }
            }
        }

        return $this->_images;
    }

    protected function getFileName($imageId, $version = 'original')
    {
        $folder = $this->getGalleryId();
        if (!$folder) {
            $folder = $this->tempDirectory;
        }

        return implode('/', [
            $folder,
            $imageId,
            $version . '.' . $this->extension,
        ]);
    }

    /**
     * Get url for display image
     * @param $imageId
     * @param string $version
     * @return string|null
     */
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

    /**
     * Get file path to image
     * @param $imageId
     * @param string $version
     * @return string
     */
    public function getFilePath($imageId, $version = 'original')
    {
        return $this->directory . '/' . $this->getFileName($imageId, $version);
    }

    /**
     * file path for process image. For existing images from previous request
     * @param $imageId
     * @param string $version
     * @return string
     */
    public function getImaginaryFilePath($imageId, $version = 'original')
    {
        return $this->imaginaryDirectory . '/' . $this->getFileName($imageId, $version);
    }

    /**
     * Get dir for existing owner. For delete images.
     * @return string
     * @throws Exception
     */
    public function getDirectoryPath()
    {
        return $this->directory . '/' . $this->getGalleryId();
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
            return '';
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
     * @throws \Throwable
     */
    public function deleteImage($imageId)
    {
        $result = false;
        if ($image = Yii::createObject(GalleryImage::class)::findOne($imageId)) {
            /** @var GalleryImage $image */
            $result = $image->delete();
        }
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
     * @throws \Throwable
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
     * @throws \yii\base\InvalidConfigException
     */
    public function deleteOrphanImages()
    {
        /** @var GalleryImage $instance */
        $instance = Yii::createObject(GalleryImage::class);
        foreach ($models = $instance::findAll([
            'type' => $this->type,
            'ownerId' => '',
        ]) as $item) {
            $item->delete();
            FileHelper::removeDirectory($this->directory . '/' . $this->tempDirectory);
        }
    }

    /**
     * Add image to a gallery table.
     * Replace filename of image to common form with galleryId
     * @param $fileName
     * @return GalleryImage
     * @throws Exception
     * @throws \yii\db\Exception
     * @throws \Throwable
     */
    public function addImage($fileName)
    {
        /** @var GalleryImage $image */
        $image = Yii::createObject($this->imageClass);
        $image->galleryBehavior = $this;
        $image->type = $this->type;
        $image->ownerId = $this->getGalleryId();

        $transaction = Yii::$app->db->beginTransaction();
        $transactionLevel = $transaction->level;
        try {
            $pass = $image->save();
            $id = $image->id;
            $image->rank = $id;
            $pass &= (int) $image->save();

            if (!$image->ownerId) {
                $this->temporaryIndex;
                $temp = Yii::createObject($this->tempClass);
                /** @var GalleryTemp $temp */
                $pass &= (int) $temp::generateTemp($id, $this->temporaryIndex);
            }

            $this->replaceImage($id, $fileName);

            $transaction->commit();
        } catch (\Exception $e) {
            if ($transaction->isActive && $transactionLevel == $transaction->level) {
                $transaction->rollBack();
            }
            throw $e;
        } catch (\Throwable $e) {
            if ($transaction->isActive && $transactionLevel == $transaction->level) {
                $transaction->rollBack();
            }
            throw $e;
        }

        if ($this->_images !== null) {
            $this->_images[] = $image;
        }

        return $image;
    }

    /**
     * Change order of images
     * @param $order
     * @return mixed
     * @throws \yii\db\Exception
     * @throws \yii\base\InvalidConfigException
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

            /** @var GalleryImage $instance */
            $instance = Yii::createObject(GalleryImage::class);
            $instance::updateAll(['rank' => $orders[$i]], ['id' => $k]);

            $i++;
        }

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
            /** @var GalleryImage $instance */
            $instance = Yii::createObject(GalleryImage::class);
            foreach ($models = $instance::find()
                ->where([
                    'type' => $this->type,
                    'ownerId' => $this->getGalleryId(), // empty for isNewRecord of owner
                ])
                ->andWhere(['in', 'id', $imageIds])
                ->orderBy(['rank' => 'asc'])
                ->all() as $item) {
                /** @var GalleryImage $item */
                $item->galleryBehavior = $this;
                $imagesToUpdate[] = $item;
            }
        }
        foreach ($imagesToUpdate as $image) {
            if (isset($imagesData[$image->id]['name'])) {
                $image->name = $imagesData[$image->id]['name'];
            }
            if (isset($imagesData[$image->id]['description'])) {
                $image->description = $imagesData[$image->id]['description'];
            }
            $image->save();
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
