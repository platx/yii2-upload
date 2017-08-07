Yii2 Upload behavior
======================
Behavior for ActiveRecord models for easy file uploading. 
Following formats of uploads are supported:
 * POST method upload (http://php.net/manual/en/features.file-upload.post-method.php)
 * Remote file url
 * Base64
 * Server path

Installation
------------

The preferred way to install this extension is through [composer](http://getcomposer.org/download/).

Either run

```
php composer.phar require --prefer-dist platx/yii2-upload "*"
```

or add

```
"platx/yii2-upload": "*"
```

to the require section of your `composer.json` file.


Configuring
-----

Attach the behavior in your ActiveRecord model:

```php
public function behaviors()
{
    return [
        'upload' => [
            'class' => 'platx\upload\FileUploadBehavior',
            'attributes' => ['image'], // Model attributes to handle
            'scenarios' => ['default'], // Scenarios to handle
            'basePath' => '@app/web/uploads', // Base path on server to store files
            'baseUrl' => '/uploads', // Base url to make url
            // Additional configuration
        ]
    ];
}
```

Configuration options for platx\upload\FileUploadBehavior:

 * **attributes** - Model attribute names that should be handled. Type: `array`. `Required parameter`.
 * **scenarios** - Scenarios to handle (by default scenarios from owner model taken). Type: `array`.
 * **prefix** - Prefix for file attribute, which will be posted. Type: `string`. Default value: `file_`.
 * **basePath** - Server path to store files (with alias or absolute server path). Type: `string`. Default value: `@app/web/uploads`.
 * **baseUrl** - Base url to build url link to file. Type: `string`. Default value: `/uploads`.
 * **handleNotUploadedFiles** - Whether handle not POST method uploads (*base64*, *remote url*, *server path*). Type: `boolean`. Default value: `false`.
 * **nameTemplate** - File name template or generating new name. Default value: `{id}_{name}.{ext} `. Can be:
    - `true` - Generate new file name
    - `false` - Keep original file name
    - `string` - Template to generate new file name, can be with variables:
        - `{id}` - Record id (if is composite key, will be imploded)
        - `{attribute}` - Model attribute name
        - `{name}` - File name
        - `{ext}` - File extension
    - `\Closure` - the callback function to be executed. The anonymous
        function should have the following signature:
        ```php
        function ($model, $attribute, $file)
        ```
        where:
            - `$model` - Owner model instance
            - `$attribute` -  Current attribute name for file upload
            - `$file` -  Current UploadedFile instance to save
        For example:
        ```php
        function ($model, $attribute, $file) {
            return 'file_' . $model->id . '.' .$file->extension;
        }
        ```
 * **modelFolder** - Whether add model name folder. Default value: `true`. Can be:
    - `true` - Include model folder
    - `false` - Do not include model folder
    - `string` - Static string to be included
    - `\Closure` - The callback function to be executed. The anonymous
        function should have the following signature:
        ```php
        function ($model, $attribute, $file)
        ```
        where:
            - `$model`: Owner model instance
            - `$attribute`: Current attribute name for file upload
            - `$file`: Current UploadedFile instance to upload
        For example:
        ```php
        function ($model, $attribute, $file) {
            return 'my_' . $model::getTableSchema()->fullName;
        }
        ```
 * **dynamicFolder** - Whether generate dynamic folder. Default value: `true`. Can be:
    - `true` - Generate dynamic folder (3 nesting levels), using model primary key
    - `false` - Do not include dynamic folder
    - `string` - Static string
    - `\Closure` - The callback function to be executed. The anonymous
        function should have the following signature:
        ```php
        function ($model, $attribute, $file)
        ```
        where:
            - `$model`: Owner model instance
            - `$attribute`: Current attribute name for file upload
            - `$file`: Current UploadedFile instance to upload
        For example:
        ```php
        function ($model, $attribute, $file) {
            return $model::getTableSchema()->fullName . '_' .uniqid();
        }
        ```
 * **attributeFolder** - Whether add attribute folder. Default value: `true`. Can be:
    - `true` - Add folder with attribute name
    - `false` - Do not include attribute folder
    - `string` - Static string
    - `\Closure` - The callback function to be executed. The anonymous
        function should have the following signature:
        ```php
        function ($model, $attribute, $file)
        ```
        where:
            - `$model`: Owner model instance
            - `$attribute`: Current attribute name for file upload
            - `$file`: Current UploadedFile instance to upload
        For example:
        ```php
        function ($model, $attribute, $file) {
            return $model::getTableSchema()->fullName . '_' .uniqid();
        }
        ```
 * **instanceByName** - Whether get file instance by attribute name. Type: `boolean`. Default value: `false`.
 * **deleteWithOwner** - Whether delete file with model record deleting. Type: `boolean`. Default value: `true`.
 * **deleteTempFile** - Whether delete temporary file after saving. Type: `boolean`. Default value: `true`.
 * **messageUploadNotSupported** - Error message if upload type is not supported. Type: `string`. Default value: `From message source`.
 * **messageUnableSaveFile** - Error message if unable to save file to destination folder. Type: `string`. Default value: `From message source`.
 * **messageUnableHandleFile** - Error message if unable to handle upload and make UploadedFile instance. Type: `string`. Default value: `From message source`.
 * **messageUnableCreateDirectory** - Error message if unable to create destination folder for file. Type: `string`. Default value: `From message source`.


Usage
-----

Example view file:
```text
<?php $form = \yii\bootstrap\ActiveForm::begin(['options' => ['enctype' => 'multipart/form-data']]); ?>
    <?= $form->field($model, 'file_image')->fileInput() ?>
    <div class="form-group">
        <?= \yii\helpers\Html::submitButton('Upload', ['class' => 'btn btn-primary']) ?>
    </div>
<?php $form->end(); ?>
```
