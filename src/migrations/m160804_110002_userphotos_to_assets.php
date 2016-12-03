<?php

namespace craft\migrations;

use Craft;
use craft\dates\DateTime;
use craft\db\Migration;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\ElementHelper;
use craft\helpers\FileHelper;
use craft\helpers\Image;
use craft\helpers\Io;
use craft\helpers\Json;
use craft\helpers\StringHelper;

/**
 * m160804_110002_userphotos_to_assets migration.
 */
class m160804_110002_userphotos_to_assets extends Migration
{
    /**
     * @var string
     */
    private $_basePath;

    /**
     * @inheritdoc
     */
    public function safeUp()
    {
        $this->_basePath = Craft::$app->getPath()->getStoragePath().'/'.'userphotos';
        Craft::info('Removing __default__ folder');
        FileHelper::removeDirectory($this->_basePath.'/__default__');

        Craft::info('Changing the relative path from username/original.ext to original.ext');
        $affectedUsers = $this->_moveUserphotos();

        Craft::info('Creating a private Yii Volume as default for Users');
        $volumeId = $this->_createUserphotoVolume();

        Craft::info('Setting the Volume as the default one for userphoto uploads');
        $this->_setUserphotoVolume($volumeId);

        Craft::info('Converting photos to Assets');
        $affectedUsers = $this->_convertPhotosToAssets($volumeId, $affectedUsers);

        Craft::info('Updating Users table to drop the photo column and add photoId column.');
        $this->dropColumn('{{%users}}', 'photo');
        $this->addColumn('{{%users}}', 'photoId', $this->integer()->null());
        $this->addForeignKey($this->db->getForeignKeyName('{{%users}}', 'photoId'), '{{%users}}', 'photoId', '{{%assets}}', 'id', 'SET NULL', null);

        Craft::info('Setting the photoId value');
        $this->_setPhotoIdValues($affectedUsers);

        Craft::info('Removing all the subfolders.');
        $this->_removeSubdirectories();

        Craft::info('All done');

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown()
    {
        echo 'm160804_110002_userphotos_to_assets cannot be reverted.\n';
        return false;
    }

    // Private methods
    // =========================================================================

    /**
     * Move user photos from subfolders to root.
     *
     * @return array
     */
    private function _moveUserphotos()
    {
        $affectedUsers = [];
        $subfolders = Io::getFolderContents($this->_basePath, false);

        if ($subfolders) {
            // Grab the users with photos
            foreach ($subfolders as $subfolder) {
                $usernameOrEmail = trim(StringHelper::replace($subfolder, $this->_basePath, ''), '/');

                $user = (new Query())
                    ->select(['id', 'photo'])
                    ->from(['{{%users}}'])
                    ->where(['username' => $usernameOrEmail])
                    ->one();

                $sourcePath = $subfolder.'/original/'.$user['photo'];

                // If the file actually exists
                if (is_file($sourcePath)) {
                    // Make sure that the filename is unique
                    $counter = 0;

                    $baseFilename = pathinfo($user['photo'], PATHINFO_FILENAME);
                    $extension = pathinfo($user['photo'], PATHINFO_EXTENSION);
                    $filename = $baseFilename.'.'.$extension;

                    while (is_file($this->_basePath.DIRECTORY_SEPARATOR.$filename)) {
                        $filename = $baseFilename.'_'.++$counter.'.'.$extension;
                    }

                    // In case the filename changed
                    $user['photo'] = $filename;

                    // Store for reference
                    $affectedUsers[] = $user;

                    $targetPath = $this->_basePath.'/'.$filename;

                    // Move the file to the new location
                    Io::move($sourcePath, $targetPath);
                }
            }
        }

        return $affectedUsers;
    }

    /**
     * Create the user photo volume.
     *
     * @return integer volume id
     */
    private function _createUserphotoVolume()
    {
        // Safety first!
        $handle = 'userPhotos';
        $name = 'User Photos';

        $counter = 0;

        $existingVolume = (new Query())
            ->select(['id'])
            ->from(['{{%volumes}}'])
            ->where(['handle' => $handle])
            ->one();

        while (!empty($existingVolume)) {
            $handle = 'userPhotos'.++$counter;
            $name = 'User Photos '.$counter;
            $existingVolume = (new Query())
                ->select(['id'])
                ->from(['{{%volumes}}'])
                ->where([
                    'or',
                    ['handle' => $handle],
                    ['name' => $name]
                ])
                ->one();
        }

        // Set the sort order
        $maxSortOrder = (new Query())
            ->from(['{{%volumes}}'])
            ->max('[[sortOrder]]');

        $volumeData = [
            'type' => 'craft\volumes\Local',
            'name' => $name,
            'handle' => $handle,
            'hasUrls' => null,
            'url' => null,
            'settings' => Json::encode(['path' => $this->_basePath]),
            'fieldLayoutId' => null,
            'sortOrder' => $maxSortOrder + 1
        ];

        $db = Craft::$app->getDb();
        $db->createCommand()
            ->insert('{{%volumes}}', $volumeData)
            ->execute();

        $volumeId = $db->getLastInsertID();

        $folderData = [
            'parentId' => null,
            'volumeId' => $volumeId,
            'name' => $name,
            'path' => null
        ];
        $db->createCommand()
            ->insert('{{%volumefolders}}', $folderData)
            ->execute();

        return $volumeId;
    }

    /**
     * Set the photo volume setting for users.
     *
     * @param integer $volumeId
     *
     * @return void
     */
    private function _setUserphotoVolume($volumeId)
    {
        $systemSettings = Craft::$app->getSystemSettings();
        $settings = $systemSettings->getSettings('users');
        $settings['photoVolumeId'] = $volumeId;
        $systemSettings->saveSettings('users', $settings);
    }

    /**
     * Convert matching user photos to Assets in a Volume and add that information
     * to the array passed in.
     *
     * @param integer $volumeId
     * @param array $userList
     *
     * @return array $userList
     */
    private function _convertPhotosToAssets($volumeId, $userList)
    {
        $db = Craft::$app->getDb();

        $locales = (new Query())
            ->select(['locale'])
            ->from(['{{%locales}}'])
            ->column();

        $folderId = (new Query())
            ->select(['id'])
            ->from(['{{%volumefolders}}'])
            ->where([
                'parentId' => null,
                'volumeId' => $volumeId
            ])
            ->scalar();

        $changes = [];

        foreach ($userList as $user) {
            $filePath = $this->_basePath.'/'.$user['photo'];

            $assetExists = (new Query())
                ->select(['assets.id'])
                ->from(['{{%assets}} assets'])
                ->innerJoin('{{%volumefolders}} volumefolders', '[[volumefolders.id]] = [[assets.folderId]]')
                ->where([
                    'assets.folderId' => $folderId,
                    'filename' => $user['photo']
                ])
                ->one();

            if (!$assetExists && is_file($filePath)) {
                $elementData = [
                    'type' => 'craft\elements\Asset',
                    'enabled' => 1,
                    'archived' => 0
                ];
                $db->createCommand()
                    ->insert('{{%elements}}', $elementData)
                    ->execute();

                $elementId = $db->getLastInsertID();

                foreach ($locales as $locale) {
                    $elementI18nData = [
                        'elementId' => $elementId,
                        'locale' => $locale,
                        'slug' => ElementHelper::createSlug($user['photo']),
                        'uri' => null,
                        'enabled' => 1
                    ];
                    $db->createCommand()
                        ->insert('{{%elements_i18n}}', $elementI18nData)
                        ->execute();

                    $contentData = [
                        'elementId' => $elementId,
                        'locale' => $locale,
                        'title' => StringHelper::toTitleCase(pathinfo($user['photo'], PATHINFO_FILENAME))
                    ];
                    $db->createCommand()
                        ->insert('{{%content}}', $contentData)
                        ->execute();
                }

                $imageSize = Image::imageSize($filePath);
                $assetData = [
                    'id' => $elementId,
                    'volumeId' => $volumeId,
                    'folderId' => $folderId,
                    'filename' => $user['photo'],
                    'kind' => 'image',
                    'size' => filesize($filePath),
                    'width' => $imageSize[0],
                    'height' => $imageSize[1],
                    'dateModified' => Db::prepareDateForDb(filemtime($filePath))
                ];
                $db->createCommand()
                    ->insert('{{%assets}}', $assetData)
                    ->execute();

                $changes[$user['id']] = $db->getLastInsertID();
            }
        }

        return $changes;
    }

    /**
     * Set photo ID values for the user array passed in.
     *
     * @param array $userlist userId => assetId
     *
     * @return void
     */
    private function _setPhotoIdValues($userlist)
    {
        if (is_array($userlist)) {
            $db = Craft::$app->getDb();
            foreach ($userlist as $userId => $assetId) {
                $db->createCommand()
                    ->update('{{%users}}', ['photoId' => $assetId], ['id' => $userId])
                    ->execute();
            }
        }
    }

    /**
     * Remove all the subdirectories in the userphotos folder.
     */
    private function _removeSubdirectories()
    {
        $subDirs = glob($this->_basePath.DIRECTORY_SEPARATOR.'*', GLOB_ONLYDIR);

        foreach ($subDirs as $dir) {
            FileHelper::removeDirectory($dir);
        }
    }
}
