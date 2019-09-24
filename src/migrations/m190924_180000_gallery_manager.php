<?php

namespace bscheshirwork\yii2\galleryManager\migrations;

use Yii;
use yii\db\Schema;
use yii\db\Migration;

class m190924_180000_gallery_manager extends Migration
{

    public function up()
    {
        $this->addColumn('{{%gallery_temp}}', 'type', $this->string()->null() . ' AFTER id');
        $this->createIndex('{{%i_type}}', '{{%gallery_temp}}', 'type');

        Yii::$app->db->schema->refresh();
    }

    public function down()
    {
        $this->dropIndex('{{%i_type}}', '{{%gallery_temp}}');
        $this->dropColumn('{{%gallery_temp}}', 'type');

        Yii::$app->db->schema->refresh();
    }
}
