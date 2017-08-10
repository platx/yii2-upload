<?php

namespace platx\upload;

use yii\base\Behavior;
use yii\base\Exception;
use yii\base\InvalidConfigException;
use yii\db\BaseActiveRecord;
use yii\helpers\Url;
use yii\helpers\ArrayHelper;
use yii\helpers\FileHelper;
use yii\helpers\Inflector;
use yii\web\UploadedFile;


/**
 * Behavior for ActiveRecord models for easy handling file uploads.
 * Following formats of uploads are supported:
 *  - POST method upload (http://php.net/manual/en/features.file-upload.post-method.php)
 *  - Remote file url
 *  - Base64
 *  - Server path
 */
class FileUploadBehavior extends Behavior
{
    const EVENT_BEFORE_UPLOAD = 'beforeUpload';
    const EVENT_AFTER_UPLOAD = 'afterUpload';

    /** @var array|string Model attribute names to handle */
    public $attributes;

    /** @var string Attribute prefix with upload to handle */
    public $prefix = 'file_';

    /** @var array Scenarios to handle (if empty, takes scenarios from '[[owner]]' model) */
    public $scenarios = [];

    /** @var string Base path on server to store file */
    public $basePath = '@app/web/uploads';

    /** @var string Base url to build url links */
    public $baseUrl = '/uploads';

    /** @var bool Whether handle not POST method uploads (base64, remote url, server path) */
    public $handleNotUploadedFiles = false;

    /**
     * @var bool|string|\Closure File name template or generating new name:
     *  - true - Generate new file name
     *  - false - Keep original file name
     *  - string - template to generate new file name, can be with variables:
     *      - {id} - Record id (if is composite key, will be imploded)
     *      - {attribute} - Model attribute name
     *      - {name} - File name
     *      - {ext} - File extension
     *  - Closure the callback function to be executed. The anonymous
     *      function should have the following signature:
     *      ```php
     *      function ($model, $attribute, $file)
     *      ```
     *      where:
     *          - `$model`: Owner model instance
     *          - `$attribute`: Current attribute name for file upload
     *          - `$file`: Current UploadedFile instance to upload
     *
     *      For example:
     *      ```php
     *      function ($model, $attribute, $file) {
     *          return 'file_' . $model->id . '.' .$file->extension;
     *      }
     *      ```
     */
    public $nameTemplate = '{id}_{name}.{ext}';

    /**
     * @var bool|string|\Closure Whether add model name folder, can be:
     *  - true - Include model folder
     *  - false - Do not include model folder
     *  - string - Static string to be included
     *  - Closure the callback function to be executed. The anonymous
     *      function should have the following signature:
     *      ```php
     *      function ($model, $attribute, $file)
     *      ```
     *      where:
     *          - `$model`: Owner model instance
     *          - `$attribute`: Current attribute name for file upload
     *          - `$file`: Current UploadedFile instance to upload
     *
     *      For example:
     *      ```php
     *      function ($model, $attribute, $file) {
     *          return 'my_' . $model::getTableSchema()->fullName;
     *      }
     *      ```
     */
    public $modelFolder = true;

    /**
     * @var bool|string|\Closure Whether generate dynamic folder, can be:
     *  - true - Generate dynamic folder (3 nesting levels), using model primary key
     *  - false - Do not include dynamic folder
     *  - string - Static string
     *  - Closure the callback function to be executed. The anonymous
     *      function should have the following signature:
     *      ```php
     *      function ($model, $attribute, $file)
     *      ```
     *      where:
     *          - `$model`: Owner model instance
     *          - `$attribute`: Current attribute name for file upload
     *          - `$file`: Current UploadedFile instance to upload
     *
     *      For example:
     *      ```php
     *      function ($model, $attribute, $file) {
     *          return $model::getTableSchema()->fullName . '_' .uniqid();
     *      }
     *      ```
     */
    public $dynamicFolder = true;

    /**
     * @var bool|string|\Closure Whether add attribute folder, can be:
     *  - true - Add folder with attribute name
     *  - false - Do not include attribute folder
     *  - string - Static string
     *  - Closure the callback function to be executed. The anonymous
     *      function should have the following signature:
     *      ```php
     *      function ($model, $attribute, $file)
     *      ```
     *      where:
     *          - `$model`: Owner model instance
     *          - `$attribute`: Current attribute name for file upload
     *          - `$file`: Current UploadedFile instance to upload
     *
     *      For example:
     *      ```php
     *      function ($model, $attribute, $file) {
     *          return $model::getTableSchema()->fullName . '_' .uniqid();
     *      }
     *      ```
     */
    public $attributeFolder = true;

    /** @var bool Whether get file instance by attribute name */
    public $instanceByName = false;

    /** @var bool Whether delete file with '[[owner]]' record deleting */
    public $deleteWithOwner = true;

    /** @var bool Whether delete temporary file after saving */
    public $deleteTempFile = true;

    /** @var string Error message if upload type is not supported */
    public $messageUploadNotSupported;

    /** @var string Error message if unable to save file to destination folder */
    public $messageUnableSaveFile;

    /** @var string Error message if unable to handle upload and make UploadedFile instance */
    public $messageUnableHandleFile;

    /** @var string Error message if unable to create destination folder for file */
    public $messageUnableCreateDirectory;

    /** @var string[]|UploadedFile[]|array|null To store values before validate (from request)  */
    private $_values;

    /** @var UploadedFile[] To store files (UploadedFile instances) */
    private $_files;

    public function init()
    {
        parent::init();

        $this->registerTranslations();
        $this->_prepareAttributes();
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
     * Prepares and checks validity of '[[attributes]]' parameter
     * @throws InvalidConfigException Thrown if [[attributes]] parameter is empty
     */
    private function _prepareAttributes()
    {
        if (empty($this->attributes)) {
            throw new InvalidConfigException(\Yii::t(
                'platx/upload',
                'Parameter \'attributes\' is required'
            ));
        } else if (!is_array($this->attributes)) {
            $attributes = explode(',', $this->attributes);

            foreach ($attributes as &$attribute) {
                $attribute = trim($attribute);
            }

            $this->attributes = $attributes;
        }
    }

    /**
     * Prepares and checks validity of '[[scenarios]]' parameter.
     * If '[[scenarios]]' parameter is empty, takes scenarios from '[[owner]]'
     */
    private function _prepareScenarios()
    {
        if ($this->scenarios !== false && empty($this->scenarios)) {
            $this->scenarios = array_keys($this->getOwner()->scenarios());
        }
    }

    /**
     * Declares event handlers for the [[owner]]'s events.
     * @return array events (array keys) and the corresponding event handler methods (array values).
     */
    public function events()
    {
        return [
            BaseActiveRecord::EVENT_BEFORE_VALIDATE => 'beforeValidate',
            BaseActiveRecord::EVENT_AFTER_INSERT => 'afterSave',
            BaseActiveRecord::EVENT_AFTER_UPDATE => 'afterSave',
            BaseActiveRecord::EVENT_BEFORE_DELETE => 'beforeDelete',
        ];
    }

    /**
     * Called before owner model validate process to get UploadedFile instances from request
     */
    public function beforeValidate()
    {
        $this->_prepareScenarios();

        // Work just with allowed scenarios
        if (in_array($this->getOwner()->scenario, $this->scenarios)) {
            foreach ($this->attributes as $attribute) {
                $fileAttribute = $this->prefix . $attribute;

                $fileValue = $this->getFileValue($attribute);

                if (!($fileValue instanceof UploadedFile)) {
                    if ($this->instanceByName === true && $file = UploadedFile::getInstanceByName($fileAttribute)) {
                        $fileValue = $file;
                    } else if ($this->instanceByName !== true && $file = UploadedFile::getInstance($this->getOwner(), $fileAttribute)) {
                        $fileValue = $file;
                    } else if (!empty($fileValue) && is_string($fileValue)) {
                        if (!$this->handleNotUploadedFiles) {
                            $this->getOwner()->addError(
                                $attribute,
                                $this->messageUploadNotSupported ?
                                    $this->messageUploadNotSupported :
                                        \Yii::t( 'platx/upload', 'This type of upload is not supported')
                            );
                            continue;
                        }
                        if (preg_match('/^(http:\/\/|https:\/\/|\/\/).*/', $fileValue)) {
                            $fileValue = $this->makeTempFile($attribute);
                        } else if(preg_match('/^data:([\w\/]+);base64/i', $fileValue, $matches)){
                            $fileValue = $this->makeTempFile($attribute);
                        } else {
                            $tempPath = \Yii::getAlias(FileHelper::normalizePath($fileValue));

                            $explodedTempPath = explode(DIRECTORY_SEPARATOR, $tempPath);
                            $name = end($explodedTempPath);

                            $mimeType = FileHelper::getMimeType($tempPath);

                            $file = new UploadedFile([
                                'tempName' => $tempPath,
                                'name' => $name,
                                'type' => $mimeType,
                            ]);

                            $fileValue = $file;
                        }
                    }
                }

                if ($fileValue instanceof UploadedFile) {
                    $this->setFile($attribute, $fileValue);
                } else if (!empty($this->getFileValue($attribute))){
                    $this->getOwner()->addError(
                        $attribute,
                        $this->messageUnableHandleFile ?
                            $this->messageUnableHandleFile :
                            \Yii::t( 'platx/upload', 'Unable to handle file')
                    );
                }
            }
        }
    }

    /**
     * @return BaseActiveRecord ActiveRecord owner model
     */
    protected function getOwner()
    {
        /** @var \yii\db\BaseActiveRecord $owner */
        $owner = $this->owner;

        return $owner;
    }

    /**
     * Returns file value for attribute name if it is not empty
     * @param string $attribute Model attribute name
     * @return UploadedFile|string|null File value for attribute name
     */
    public function getFileValue($attribute)
    {
        if ($this->hasFileValue($attribute)) {
            return $this->_values[$attribute];
        }

        return null;
    }

    /**
     * Checks if file value is not empty
     * @param string $attribute Model attribute name
     * @return bool Whether value is not empty
     */
    public function hasFileValue($attribute)
    {
        return !empty($this->_values[$attribute]);
    }

    /**
     * Makes temporary file if file is not uploaded via http request
     * @param string $attribute Model attribute name
     * @return bool|UploadedFile UploadedFile instance or false if something wrong
     */
    protected function makeTempFile($attribute)
    {
        if (!$this->hasFileValue($attribute)) {
            return false;
        }

        $tempName = tempnam(sys_get_temp_dir(), 'ub_');

        if (preg_match('/^(http:\/\/|https:\/\/|\/\/).*/', $this->getFileValue($attribute))) {
            $fileInfo = pathinfo($this->getFileValue($attribute));
            $name = ArrayHelper::getValue($fileInfo, 'basename');
            $type = FileHelper::getMimeTypeByExtension(ArrayHelper::getValue($fileInfo, 'extension'));

            if (!copy($this->getFileValue($attribute), $tempName)) {
                return false;
            }
        } else if(preg_match('/^data:([\w\/]+);base64/i', $this->getFileValue($attribute), $matches)){
            list($type, $data) = explode(';', $this->getFileValue($attribute));
            list(, $data)      = explode(',', $this->getFileValue($attribute));
            $data = base64_decode($data);

            $name = uniqid('ub_');

            if (!empty($matches[1])) {
                $extensions = FileHelper::getExtensionsByMimeType($matches[1]);
                $name .= '.' . end($extensions);
            }

            if (!file_put_contents($tempName, $data)) {
                return false;
            }
        } else {
            return false;
        }

        return new UploadedFile([
            'name' => $name,
            'type' => $type,
            'tempName' => $tempName
        ]);
    }

    /**
     * Sets new UploadedFile instance to temporary variable
     * @param string $attribute Model attribute name
     * @param UploadedFile $value UploadedFile instance to set
     */
    public function setFile($attribute, $value)
    {
        $this->_files[$attribute] = $value;
    }

    /**
     * Called after saving owner model, stores file to destination folder and updates model attribute
     * @throws Exception
     */
    public function afterSave()
    {
        foreach($this->attributes as $attribute) {
            if (!$this->getFile($attribute)) {
                continue;
            }

            $link = $this->getFileLink($attribute);
            $path = $this->getFilePath($link);

            $this->getOwner()->trigger(self::EVENT_BEFORE_UPLOAD);
            $directory = dirname($path);
            if (is_string($path) && FileHelper::createDirectory($directory, 0777)) {
                if ($this->saveFile($attribute, $path)) {
                    $this->getOwner()->updateAttributes([$attribute => $link]);
                    $this->getOwner()->trigger(self::EVENT_AFTER_UPLOAD);
                }
            } else {
                $this->getOwner()->addError(
                    $attribute,
                    $this->messageUnableCreateDirectory ?
                        $this->messageUnableCreateDirectory :
                        \Yii::t( 'platx/upload', 'Unable to create directory \'{directory}\'', ['directory' => $directory])
                );
            }
        }
    }

    /**
     * To get UploadedFile instance using model attribute name
     * @param string $attribute Model attribute name
     * @return UploadedFile|null UploadedFile instance
     */
    public function getFile($attribute)
    {
        if ($this->hasFile($attribute)) {
            return $this->_files[$attribute];
        }

        return null;
    }

    /**
     * Makes file url to store in database
     * @param string $attribute Model attribute name
     * @return string File url to store in database
     */
    public function getFileLink($attribute)
    {
        $link = "{$this->getLink($attribute)}/{$this->getFileName($attribute)}";

        return FileHelper::normalizePath($link, '/');
    }

    /**
     * Makes link path (not absolute path and is not including base path and file name)
     * @param string $attribute Model attribute name
     * @return string Link name
     */
    public function getLink($attribute)
    {
        if ($this->modelFolder) {
            if ($this->modelFolder instanceof \Closure) {
                $modelFolder = call_user_func($this->modelFolder, $this->getOwner(), $attribute, $this->getFile($attribute));
            } else if (is_string($this->modelFolder)) {
                $modelFolder = $this->modelFolder;
            } else {
                $modelFolder = $this->_generateClassNameFolder($this->getOwner()->className());
            }
        } else {
            $modelFolder = null;
        }

        if ($this->dynamicFolder) {
            if ($this->dynamicFolder instanceof \Closure) {
                $dynamicFolder = call_user_func($this->dynamicFolder, $this->getOwner(), $attribute, $this->getFile($attribute));
            } else if (is_string($this->dynamicFolder)) {
                $dynamicFolder = $this->dynamicFolder;
            }  else {
                $pk = implode('_', $this->getOwner()->getPrimaryKey(true));
                $dynamicFolder = $this->_generateDynamicFolder($pk);
            }
        } else {
            $dynamicFolder = null;
        }

        if ($this->attributeFolder) {
            if ($this->attributeFolder instanceof \Closure) {
                $attributeFolder = call_user_func($this->attributeFolder, $this->getOwner(), $attribute, $this->getFile($attribute));
            } else if (is_string($this->attributeFolder)) {
                $attributeFolder = $this->attributeFolder;
            } else {
                $attributeFolder = $attribute;
            }
        } else {
            $attributeFolder = null;
        }

        return "/{$modelFolder}/{$dynamicFolder}/{$attributeFolder}";
    }

    /**
     * Generates folder name from class name
     * @param string $className Class name
     * @param bool $pluralize Whether pluralize folder name
     * @return string Generated folder
     */
    private function _generateClassNameFolder($className, $pluralize = false)
    {
        $explodedClassName = explode('\\', $className);
        $classNameShort = end($explodedClassName);

        $classNameShort = Inflector::camel2id($classNameShort);

        return $pluralize ? Inflector::pluralize($classNameShort) : $classNameShort;
    }

    /**
     * Generates dynamic folder with 3 nesting levels using record primary key
     * @param integer|string $pk Primary key (if is composite, should be imploded)
     * @return string Generated folder
     */
    private function _generateDynamicFolder($pk)
    {
        $pk = (int)$pk;

        $n = floor($pk);
        $c = floor($n/125000000);
        $a = floor((($n - $c * 125000000)/250000));
        $b = floor((($n - $a * 250000 - $c * 125000000)/500));

        return FileHelper::normalizePath("$c/$a/$b");
    }

    /**
     * Takes file name or generates new name if $nameTemplate is not empty
     * @param string $attribute Model attribute name
     * @return bool|string File name with extension
     */
    protected function getFileName($attribute)
    {
        if (!$this->hasFile($attribute)) {
            return null;
        }

        if ($this->nameTemplate) {
            if ($this->nameTemplate instanceof \Closure) {
                $name = call_user_func($this->nameTemplate, $this->getOwner(), $attribute, $this->getFile($attribute));
            } else {
                $pk = implode('_', $this->getOwner()->getPrimaryKey(true));
                $ext = $this->getFile($attribute)->extension;
                $name = str_replace(
                    ['{id}', '{attribute}', '{name}', '{ext}'],
                    [$pk, $attribute, uniqid(), $ext],
                    $this->nameTemplate
                );
            }
        } else {
            $name = $this->_sanitize($this->getFile($attribute)->name);
        }

        return $name;
    }

    /**
     * Checks if UploadedFile instance for model attribute is not empty
     * @param string $attribute Model attribute name
     * @return bool Whether file is not empty
     */
    public function hasFile($attribute)
    {
        return !empty($this->_files[$attribute]) && ($this->_files[$attribute] instanceof UploadedFile);
    }

    /**
     * Replaces bad characters in filename by -
     * @param string $filename File name to replace
     * @return boolean string Replaced file name
     */
    private function _sanitize($filename)
    {
        return str_replace([' ', '"', '\'', '&', '/', '\\', '?', '#'], '-', $filename);
    }

    /**
     * Makes absolute server path to file destination
     * @param string $link Link to file without base path
     * @return string Absolute server path
     */
    public function getFilePath($link)
    {
        $path = "{$this->basePath}/{$link}";

        return FileHelper::normalizePath(\Yii::getAlias($path));
    }

    /**
     * Stores file to specific path
     * @param string $attribute Model attribute name
     * @param string $path Absolute server path with file name
     * @return bool Result of saving process
     * @throws Exception
     */
    public function saveFile($attribute, $path)
    {
        if (!$this->hasFile($attribute)) {
            return false;
        }

        $file = $this->getFile($attribute);

        if (is_uploaded_file($file->tempName)) {
            $result = $file->saveAs($path, $this->deleteTempFile);
        } else {
            if (!$this->handleNotUploadedFiles) {
                $result = false;
            } else if ($this->deleteTempFile) {
                $result = rename($file->tempName, $path);
            } else {
                $result = copy($file->tempName, $path);
            }
        }

        if (!$result) {
            $this->getOwner()->addError(
                $attribute,
                $this->messageUnableSaveFile ?
                    $this->messageUnableSaveFile :
                    \Yii::t( 'platx/upload', 'Unable to save file')
            );
            return false;
        }

        $this->deleteFile($attribute);

        return true;
    }

    /**
     * @param $attribute
     * @param bool $isAbsolute
     * @return null|string
     */
    public function getFileUrl($attribute, $isAbsolute = false)
    {
        if (empty($this->owner->{$attribute})) {
            return null;
        }

        $link = $this->owner->{$attribute};

        return Url::to("{$this->baseUrl}/{$link}", $isAbsolute);
    }

    /**
     * Returns a value indicating whether a property can be read.
     *
     * @param string $name the property name
     * @param boolean $checkVars whether to treat member variables as properties
     *
     * @return boolean whether the property can be read
     * @see canSetProperty()
     */
    public function canGetProperty($name, $checkVars = true)
    {
        if (strpos($name, $this->prefix) !== false) {
            $name = str_replace($this->prefix, '', $name);
        }

        return in_array($name, $this->attributes) ? true : parent::canGetProperty($name, $checkVars);
    }

    /**
     * Returns a value indicating whether a property can be set.
     *
     * @param string $name the property name
     * @param boolean $checkVars whether to treat member variables as properties
     * @param boolean $checkBehaviors whether to treat behaviors' properties as properties of this component
     *
     * @return boolean whether the property can be written
     * @see canGetProperty()
     */
    public function canSetProperty($name, $checkVars = true, $checkBehaviors = true)
    {
        if (strpos($name, $this->prefix) !== false) {
            $name = str_replace($this->prefix, '', $name);
        }

        return in_array($name, $this->attributes) ? true : parent::canSetProperty($name, $checkVars);
    }

    /**
     * @param string $name
     *
     * @return mixed|null
     */
    public function __get($name)
    {
        if (strpos($name, $this->prefix) !== false) {
            $name = str_replace($this->prefix, '', $name);

            return $this->getFileValue($name);
        }

        return parent::__get($name);
    }

    /**
     * @param string $name
     * @param mixed $value
     */
    public function __set($name, $value)
    {
        if (strpos($name, $this->prefix) !== false) {
            $name = str_replace($this->prefix, '', $name);

            $this->setFileValue($name, $value);
        } else {
            parent::__set($name, $value);
        }
    }

    /**
     * @param string $attribute Attribute name
     * @param UploadedFile|string $value Uploaded file to set
     */
    public function setFileValue($attribute, $value)
    {
        $this->_values[$attribute] = $value;
    }

    public function beforeDelete()
    {
        if ($this->deleteWithOwner) {
            foreach ($this->attributes as $attribute) {
                $this->deleteFile($attribute);
            }
        }
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
}