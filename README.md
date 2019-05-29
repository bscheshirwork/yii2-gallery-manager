# Gallery Manager usage instructions

Yii2 port of https://github.com/zxbodya/yii-gallery-manager

(frontend part mostly without changes, but backend was rewritten almost completely)

Gallery manager screenshots (yii 1.x version, new one has bootstrap 3 styles):

![GalleryManager images list](http://zxbodya.cc.ua/scrup/ci/eh1n1th6o0c80.png "Gallery Manager Screenshot")

Few more screenshots:
[drag & drop upload](http://zxbodya.cc.ua/scrup/6w/64q4icig84oo0.png "Drag & Drop image upload"), [editing image information](http://zxbodya.cc.ua/scrup/za/gfc68h5b4gksg.png "Edit image information"), [upload progress](http://zxbodya.cc.ua/scrup/8v/tijrezh7oksk8.png "upload progress"), 


## Features

1. AJAX image upload
2. Optional name and description for each image
3. Possibility to arrange images in gallery
4. Ability to generate few versions for each image with different configurations
5. Drag & Drop

## Decencies

1. Yii2
2. Twitter bootstrap assets (version 3)
3. Imaginary docker image
4. JQuery UI (included with Yii)

## Installation:
The preferred way to install this extension is through [composer](https://getcomposer.org/).

Either run

`php composer.phar require --prefer-dist bscheshirwork/yii2-gallery-manager-imaginary "*@dev"`

or add

`"bscheshirwork/yii2-gallery-manager-imaginary": "*@dev"`

to the require section of your `composer.json` file.

## Usage

### docker-compose
```
  imaginary:
    image: h2non/imaginary:latest
    # optionally mount a volume as local image source
    volumes:
      - ./storage/web:/mnt/data
    environment:
      PORT: 9000
    command: -enable-url-source -mount /mnt/data
    expose:
      - "9000" #for service php
    ports:
      - "9000:9000"
```

### Prepare
Add migration namespace to console config:

```php
return [
    'id' => 'app-console',
...
    'controllerMap' => [
        'migrate' => [
            'class' => yii\console\controllers\MigrateController::class,
            // Since version 2.0.12 an array can be specified for loading migrations from multiple sources.
            'migrationPath' => [
                '@app/migrations',
                '@yii/rbac/migrations/',
            ],
            'migrationNamespaces' => [
                'bscheshirwork\yii2\galleryManager\migrations',
                ...
            ],
        ],
...
```

### Add configurations for upload and store images

Add GalleryBehavior to your model, and configure it, create folder for uploaded files.

```php
use bscheshirwork\yii2\galleryManager\GalleryBehavior;

class Product extends \yii\db\ActiveRecord 
{
...
public function behaviors()
{
        $model = $this;

        return [
            'galleryBehavior' => [
                'class' => GalleryBehavior::class,
                'type' => 'nestedObject', // @see CrudController::actions()
                'extension' => 'jpg',
                'directory' => Yii::getAlias('@storageWeb') . ($galleryPath = '/images/gallery/product'),
                'url' => Yii::getAlias('@storageUrl') . $galleryPath,
                'imaginaryDirectory' => $galleryPath,
                'versions' => [
                    'small' => function ($originalImagePath, $originalImagePathForImagine) use ($model) {
                        $width = 400;
                        $height = 274;
                        /** @var GalleryBehavior $behavior */
                        $behavior = $model->getBehavior('galleryBehavior');
                        $httpQuery = http_build_query([
                            'file' => $originalImagePathForImagine,
                            'width' => $width,
                            'height' => $height,
                        ]);

                        $url = $behavior->imaginary . '/crop?' . $httpQuery;

                        return file_get_contents($url);
                    },
                ],
            ],
        ];
}
```

See also [documentations of imaginary http API](https://github.com/h2non/imaginary#http-api) for image transformations. 

Add GalleryManagerAction in controller somewhere in your application. Also on this step you can add some security checks for this action.

```php
use bscheshirwork\yii2\galleryManager\GalleryManagerAction;

class ProductController extends Controller
{
...
public function actions()
{
    return [
       'galleryApi' => [
           'class' => GalleryManagerAction::className(),
           // mappings between type names and model classes (should be the same as in behaviour)
           'types' => [
               'product' => Product::className()
           ]
       ],
    ];
}
```
        
Add ImageAttachmentWidget somewhere in you application, for example in editing from.

```php
use bscheshirwork\yii2\galleryManager\GalleryManager;

/* @var $this yii\web\View */
/* @var $model Product */
?>
...
<?php
if ($model->isNewRecord) {
    echo 'Can not upload images for new record';
} else {
    echo GalleryManager::widget(
        [
            'model' => $model,
            'behaviorName' => 'galleryBehavior',
            'apiRoute' => 'product/galleryApi'
        ]
    );
}
?>
```
        
Done!

### Get uploaded images
Now, you can use uploaded images from gallery like following:

```php
foreach($model->getBehavior('galleryBehavior')->getImages() as $image) {
    echo Html::img($image->getUrl('medium'));
}
```


## Options 

### Using non default table name for gallery images(default is `{{%gallery_image}}`):

1. Add migration that will create table you need
2. Change `tableName` property in behavior configuration
