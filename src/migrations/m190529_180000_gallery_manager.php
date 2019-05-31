<?php

namespace bscheshirwork\yii2\galleryManager\migrations;

use Yii;
use yii\db\Schema;
use yii\db\Migration;

class m190529_180000_gallery_manager extends Migration
{

    public function up()
    {
        $tableName = $this->db->tablePrefix . 'gallery_image';
        if ($this->db->getTableSchema($tableName, true) === null) {
            $this->createTable(
                '{{%gallery_image}}',
                [
                    'id' => Schema::TYPE_PK,
                    'type' => Schema::TYPE_STRING,
                    'ownerId' => Schema::TYPE_STRING . ' NOT NULL',
                    'rank' => Schema::TYPE_INTEGER . ' NOT NULL DEFAULT 0',
                    'name' => Schema::TYPE_STRING,
                    'description' => Schema::TYPE_TEXT
                ]
            );
        }
        $this->createIndex('{{%i_type}}', '{{%gallery_image}}', 'type');
        $this->createIndex('{{%i_owner}}', '{{%gallery_image}}', 'ownerId');

        $this->createTable(
            '{{%gallery_temp}}',
            [
                'id' => Schema::TYPE_PK,
                'imageId' => Schema::TYPE_INTEGER,
                'temporaryIndex' => Schema::TYPE_INTEGER,
                'csrfToken' => Schema::TYPE_STRING,
                'userIdentityId' => Schema::TYPE_STRING,
                'sessionId' => Schema::TYPE_STRING,
            ]
        );
        $this->createIndex('{{%i_uiid}}', '{{%gallery_temp}}', 'userIdentityId');
        $this->addForeignKey('{{%fk_gallery_temp_to_gallery}}', '{{%gallery_temp}}', 'imageId', '{{%gallery_image}}', 'id', 'CASCADE', 'CASCADE');

        Yii::$app->db->schema->refresh();
    }

    public function down()
    {
        $this->dropIndex('{{%i_owner}}', '{{%gallery_image}}');
        $this->dropIndex('{{%i_type}}', '{{%gallery_image}}');
        $this->dropTable('{{%gallery_temp}}');

        Yii::$app->db->schema->refresh();
    }
}
