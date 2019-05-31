<?php

namespace bscheshirwork\yii2\galleryManager;

use Yii;
use yii\base\Exception;
use yii\helpers\Json;
use yii\helpers\Url;

/**
 * Widget to manage gallery for new model.
 *
 * @author Bogdan Stepanenko <bscheshir.work@gmail.com>
 */
class MultipleInputGalleryManager extends GalleryManager
{
    /**
     * indexPlaceholder used in MultipleInput for replace by index if surrounded {}
     * @var string
     */
    public $indexPlaceholder = '';

    /**
     * {@inheritDoc}
     * Render widget
     * @throws Exception
     */
    public function run()
    {
        if ($this->apiRoute === null) {
            throw new Exception('$apiRoute must be set.', 500);
        }

        $this->id = $this->id . '-{' . $this->indexPlaceholder . '}';
        $this->behavior->temporaryIndex = '{' . $this->indexPlaceholder . '}';

        $images = [];
        $baseUrl = [
            $this->apiRoute,
            'type' => $this->behavior->type,
            'behaviorName' => $this->behaviorName,
            'temporaryIndex' => $this->behavior->temporaryIndex,
        ];

        $opts = [
            'hasName' => $this->behavior->hasName ? true : false,
            'hasDesc' => $this->behavior->hasDescription ? true : false,
            'uploadUrl' => Url::to($baseUrl + ['action' => 'ajaxUpload']),
            'deleteUrl' => Url::to($baseUrl + ['action' => 'delete']),
            'updateUrl' => Url::to($baseUrl + ['action' => 'changeData']),
            'arrangeUrl' => Url::to($baseUrl + ['action' => 'order']),
            'nameLabel' => Yii::t('galleryManager/main', 'Name'),
            'descriptionLabel' => Yii::t('galleryManager/main', 'Description'),
            'photos' => $images,
        ];

        $opts = Json::encode($opts);
        $view = $this->getView();
        GalleryManagerAsset::register($view);
        $js = <<<JS
var initMultipleInputGalleryManager = function(index) {
    let id = '{$this->id}'.replaceAll('{{$this->indexPlaceholder}}', index);
    let opts = {$opts};
    $.each(opts, function( key, value ) {
        if (typeof( value ) == 'string') {
            opts[key] = value.replaceAll('{{$this->indexPlaceholder}}', index).replaceAll('%7B{$this->indexPlaceholder}%7D', index);
        }
    });
    $("#"+id).galleryManager({$opts});
} 
JS;
        $view->registerJs($js);

        $this->options['id'] = $this->id;
        $this->options['class'] = 'gallery-manager';

        return $this->render('galleryManager');
    }

}
