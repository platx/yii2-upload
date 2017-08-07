<?php

namespace platx\upload;

use frontend\base\Action;
use Imagine\Image\Box;
use Imagine\Image\ImageInterface;
use Imagine\Imagick\Imagine;
use yii\base\InvalidConfigException;
use yii\helpers\FileHelper;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;


/**
 * Class ImageAction
 */
class ImageAction extends Action
{
    /**
     * @var array Allowed sizes to generate,
     *  if is empty, any size is allowed
     */
    public $sizeList = [];

    /** @var string $modelClass Class name of model to handle */
    public $modelClass;

    public function init()
    {
        parent::init();

        $this->registerTranslations();
    }

    /**
     * Registers translations for message sources
     */
    protected function registerTranslations()
    {
        $i18n = \Yii::$app->i18n;

        if ($i18n) {
            $i18n->translations['platx/upload/action'] = [
                'class' => 'yii\i18n\PhpMessageSource',
                'sourceLanguage' => 'en-US',
                'basePath' => __DIR__ . '/messages',
                'fileMap' => [
                    'platx/upload/action' => 'action.php',
                ],
            ];
        }
    }

    public function run($width, $height, $link)
    {
        if($this->sizeList && !in_array("{$width}x{$height}", $this->sizeList) && ($width > 0 || $height > 0)){
            throw new InvalidConfigException(\Yii::t(
                'platx/upload/action',
                'Parameters width and height are required'
            ));
        }

        $model = \Yii::createObject($this->modelClass);

        if (!($model instanceof \yii\db\BaseActiveRecord)) {
            throw new InvalidConfigException(\Yii::t(
                'platx/upload/action',
                'Parameter \'modelClass\' is required'
            ));
        }

        $model->ensureBehaviors();
        $behaviors = $model->getBehaviors();

        foreach ($behaviors as $behavior) {
            if ($behavior instanceof ImageUploadBehavior) {
                $this->handle($width, $height, $link, $behavior);
            }
        }
    }

    /**
     * @param $width
     * @param $height
     * @param $link
     * @param $behavior
     * @throws ForbiddenHttpException
     * @throws InvalidConfigException
     * @throws NotFoundHttpException
     */
    protected function handle($width, $height, $link, $behavior)
    {
        $path = FileHelper::normalizePath(\Yii::getAlias("{$behavior->basePath}/{$behavior->sizeFolder}/{$link}"));

        if (!file_exists($path)) {
            throw new NotFoundHttpException(\Yii::t(
                'platx/upload/action',
                'File \'{name}\' not found',
                ['name' => basename($path)]
            ));
        }

        if(!is_array(getimagesize($path))){
            throw new ForbiddenHttpException(\Yii::t(
                'platx/upload/action',
                'File \'{name}\' is not an image',
                ['name' => basename($path)]
            ));
        }

        $newPath = FileHelper::normalizePath(\Yii::getAlias("{$behavior->basePath}/{$width}x{$height}/{$link}"));

        if (!file_exists($newPath)) {

            $dirName = dirname($newPath);

            if (!FileHelper::createDirectory($dirName, 0777)) {
                throw new ForbiddenHttpException(\Yii::t(
                    'platx/upload/action',
                    'Can not create directory \'{directory}\'',
                    ['directory' => $dirName]
                ));
            }

            if (!extension_loaded('imagick')) {
                throw new InvalidConfigException(\Yii::t(
                    'platx/upload/action',
                    'Imagick not installed'
                ));
            }

            $image = (new Imagine())->open($path);

            if (!$width || !$height) {
                $ratio = $image->getSize()->getWidth() / $image->getSize()->getHeight();
                if ($width) {
                    $height = ceil($width / $ratio);
                } else {
                    $width = ceil($height * $ratio);
                }
            }

            $extensions = FileHelper::getExtensionsByMimeType(FileHelper::getMimeType($path));

            $image->thumbnail(new Box($width, $height), ImageInterface::THUMBNAIL_OUTBOUND)
                ->save($newPath)->show(end($extensions));
        }
    }
}