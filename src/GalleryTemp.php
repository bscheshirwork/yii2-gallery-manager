<?php

namespace bscheshirwork\yii2\galleryManager;

use Yii;

/**
 * Class GalleryTemp
 * This is the model class for table "{{%gallery_temp}}"
 *
 * @property int $id
 * @property string $type
 * @property int $imageId
 * @property int $temporaryIndex
 * @property string $csrfToken
 * @property string $userIdentityId
 * @property string $sessionId
 *
 * @package bscheshirwork\yii2\galleryManager
 */
class GalleryTemp extends \yii\db\ActiveRecord
{
    /**
     * {@inheritdoc}
     */
    public static function tableName()
    {
        return 'gallery_temp';
    }

    /**
     * Token from previous request.
     * @return mixed
     */
    private static function csrfToken()
    {
        return Yii::$app->request->getBodyParam(Yii::$app->request->csrfParam);
    }

    /**
     * Get image Id list for temporary tickets
     * @param $type
     * @param $temporaryIndex
     * @return array
     */
    public static function imageIdsFromTemp($type, $temporaryIndex)
    {
        $ids = static::find()
            ->where([
                'type' => $type,
                'temporaryIndex' => $temporaryIndex,
                'csrfToken' => static::csrfToken(),
            ])
            ->andWhere(($uid = Yii::$app->user->getId()) ? [
                'userIdentityId' => $uid,
            ] : [
                'sessionId' => Yii::$app->session->getId(),
            ])
            ->asArray()->select(['imageId'])->column();

        return $ids;
    }

    /**
     * Generate new temporary ticket
     * @param $type
     * @param $imageId
     * @param $temporaryIndex
     * @return bool
     */
    public static function generateTemp($type, $imageId, $temporaryIndex)
    {
        $model = new static;
        $model->type = $type;
        $model->imageId = $imageId;
        $model->temporaryIndex = $temporaryIndex;
        $model->csrfToken = static::csrfToken();
        $model->sessionId = Yii::$app->session->getId();
        $model->userIdentityId = Yii::$app->user->getId();

        return $model->save();
    }

    /**
     * Regenerate tokens on ticket for next request
     * @param $type
     * @param $temporaryIndex
     * @param array $imageIds
     * @return int
     */
    public static function regenerateTemps($type, $temporaryIndex, array $imageIds = [])
    {
        $model = new static;
        $condition = [
            'type' => $type,
            'temporaryIndex' => $temporaryIndex,
            'csrfToken' => static::csrfToken(),
        ];
        if ($imageIds) {
            $condition['imageId'] = $imageIds;
        }

        return $model::updateAll([
            'csrfToken' => Yii::$app->request->csrfToken,
        ], $condition);
    }

}
