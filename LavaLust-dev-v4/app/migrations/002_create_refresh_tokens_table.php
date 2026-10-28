<?php

class Create_refresh_tokens_table {

    private $_lava;

    public function __construct()
    {
        $this->_lava = lava_instance();
        $this->_lava->call->dbforge();
    }

    public function up()
    {
        if ($this->_lava->dbforge->table_exists('refresh_tokens')) {
            return;
        }

        $this->_lava->dbforge
            ->add_field([
                'id' => [
                    'type'           => 'INT',
                    'unsigned'       => TRUE,
                    'auto_increment' => TRUE,
                    'null'           => FALSE,
                ],
                'user_id' => [
                    'type'     => 'INT',
                    'unsigned' => TRUE,
                    'null'     => FALSE,
                ],
                'token_hash' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 255,
                    'null' => FALSE,
                ],
                'expires_at' => [
                    'type' => 'TIMESTAMP',
                    'null' => FALSE,
                ],
                'created_at' => [
                    'type'    => 'TIMESTAMP',
                    'default' => 'CURRENT_TIMESTAMP',
                    'null'    => FALSE,
                ],
                'jti' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 64,
                    'null' => FALSE,
                ],
            ])
            ->add_key('id', primary: TRUE)
            ->add_key('user_id', name: 'user_id_idx')
            ->add_key('token_hash', name: 'token_hash_idx')
            ->add_key('expires_at', name: 'expires_at_idx')
            ->create_table('refresh_tokens');
    }

    public function down()
    {
        $this->_lava->dbforge->drop_table('refresh_tokens');
    }
}