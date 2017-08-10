<?php

namespace platx\upload;

use Imagick;
use yii\base\Exception;
use yii\base\InvalidConfigException;
use yii\helpers\Url;
use yii\helpers\FileHelper;


/**
 * Class ImageUploadBehavior
 */
class ImageUploadBehavior extends FileUploadBehavior
{
    const EVENT_BEFORE_UPLOAD = 'beforeUpload';
    const EVENT_AFTER_UPLOAD = 'afterUpload';

    /** @var bool|string Size folder to add */
    public $originalFolder = 'original';

    /** @var bool Whether to fix jpeg image orientation */
    public $fixImageOrientation = false;

    /**
     * Deletes stored file
     * @param string $attribute Model attribute name
     * @param $path
     * @return bool Result of deleting process
     * @throws Exception
     */
    public function saveFile($attribute, $path)
    {
        if (parent::saveFile($attribute, $path)) {
            $file = $this->getFile($attribute);

            if($this->fixImageOrientation && $file && $file->type == 'image/jpeg') {
                $this->fixOrientation($path);
            }

            return true;
        }

        return false;
    }

    /**
     * Deletes stored file
     * @param string $attribute Model attribute name
     * @return bool Result of deleting process
     */
    public function deleteFile($attribute)
    {
        if (empty($this->owner->{$attribute})) {
            return null;
        }

        $link = $this->owner->{$attribute};

        $path = FileHelper::normalizePath(\Yii::getAlias("{$this->basePath}/{$link}"));

        if (file_exists($path)) {
            return unlink($path);
        }

        return false;
    }

    /**
     * @param string $url
     * @return string
     */
    public function getFilePath($url)
    {
        $path = "{$this->basePath}/{$this->originalFolder}/{$url}";

        return FileHelper::normalizePath(\Yii::getAlias($path));
    }

    /**
     * @param $attribute
     * @param null $size
     * @param bool $isAbsolute
     * @return null|string
     */
    public function getFileUrl($attribute, $isAbsolute = false, $size = null)
    {
        if (empty($this->owner->{$attribute})) {
            return null;
        }

        $link = $this->owner->{$attribute};

        if (!$size) {
            $size = $this->originalFolder;
        }

        return Url::to(FileHelper::normalizePath("{$this->baseUrl}/{$size}/{$link}"), $isAbsolute);
    }

    /**
     * @param $path
     * @throws InvalidConfigException
     */
    protected function fixOrientation($path)
    {
        if (extension_loaded('imagick')) {
            $image = new Imagick($path);
            $orientation = $image->getImageOrientation();

            switch($orientation) {
                case imagick::ORIENTATION_BOTTOMRIGHT:
                    $image->rotateimage("#000", 180); // rotate 180 degrees
                    break;

                case imagick::ORIENTATION_RIGHTTOP:
                    $image->rotateimage("#000", 90); // rotate 90 degrees CW
                    break;

                case imagick::ORIENTATION_LEFTBOTTOM:
                    $image->rotateimage("#000", -90); // rotate 90 degrees CCW
                    break;
            }

            // Now that it's auto-rotated, make sure the EXIF data is correct in case the EXIF gets saved with the image!
            $image->setImageOrientation(imagick::ORIENTATION_TOPLEFT);
            $image->writeImage($path);
        } else {
            throw new InvalidConfigException(\Yii::t(
                'platx/upload/image',
                'Imagick not installed'
            ));
        }
    }
}