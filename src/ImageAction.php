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

    /** @var string Base path to uploads on server */
    public $basePath = '@app/web/uploads';

    /** @var string Folder name for original image files */
    public $originalFolder = 'original';

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
            $i18n->translations['platx/upload'] = [
                'class' => 'yii\i18n\PhpMessageSource',
                'sourceLanguage' => 'en-US',
                'basePath' => __DIR__ . '/messages',
                'fileMap' => [
                    'platx/upload' => 'upload.php',
                ],
            ];
        }
    }

    /**
     * @param integer $width Image width
     * @param integer $height Image height
     * @param string $link File link with file name
     * @throws InvalidConfigException
     */
    public function run($width, $height, $link)
    {
        if($this->sizeList && !in_array("{$width}x{$height}", $this->sizeList) && ($width > 0 || $height > 0)){
            throw new InvalidConfigException(\Yii::t(
                'platx/upload',
                'Size {width}x{height} is not allowed',
                ['width' => $width, 'height' => $height]
            ));
        }

        $this->handle($width, $height, $link);
    }

    /**
     * @param integer $width Image width
     * @param integer $height Image height
     * @param string $link File link with file name
     * @throws ForbiddenHttpException
     * @throws InvalidConfigException
     * @throws NotFoundHttpException
     */
    protected function handle($width, $height, $link)
    {
        $path = FileHelper::normalizePath(\Yii::getAlias("{$this->basePath}/{$this->originalFolder}/{$link}"));

        if (!file_exists($path)) {
            throw new NotFoundHttpException(\Yii::t(
                'platx/upload',
                'File \'{name}\' not found',
                ['name' => basename($path)]
            ));
        }

        if(!is_array(getimagesize($path))){
            throw new ForbiddenHttpException(\Yii::t(
                'platx/upload',
                'File \'{name}\' is not an image',
                ['name' => basename($path)]
            ));
        }

        $newPath = FileHelper::normalizePath(\Yii::getAlias("{$this->basePath}/{$width}x{$height}/{$link}"));

        if (!file_exists($newPath)) {

            $dirName = dirname($newPath);

            if (!FileHelper::createDirectory($dirName, 0777)) {
                throw new ForbiddenHttpException(\Yii::t(
                    'platx/upload',
                    'Can not create directory \'{directory}\'',
                    ['directory' => $dirName]
                ));
            }

            if (!extension_loaded('imagick')) {
                throw new InvalidConfigException(\Yii::t(
                    'platx/upload',
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