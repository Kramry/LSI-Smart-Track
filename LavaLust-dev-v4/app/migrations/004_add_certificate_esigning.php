<?php

class Add_certificate_esigning
{
    private $_lava;

    public function __construct()
    {
        $this->_lava = lava_instance();
        $this->_lava->call->dbforge();
    }

    public function up()
    {
        if ($this->_lava->dbforge->table_exists('certificates')
            && $this->_lava->dbforge->column_exists('certificates', 'status')) {
            $this->_lava->dbforge->modify_column('certificates', [
                'status' => [
                    'type' => 'VARCHAR',
                    'constraint' => 32,
                    'default' => 'issued',
                    'null' => FALSE,
                ],
            ]);
        }

        if (!$this->_lava->dbforge->table_exists('certificate_signatories')) {
            $this->_lava->dbforge
                ->add_field([
                    'signatory_id' => [
                        'type' => 'INT',
                        'unsigned' => TRUE,
                        'auto_increment' => TRUE,
                        'null' => FALSE,
                    ],
                    'user_id' => [
                        'type' => 'INT',
                        'unsigned' => TRUE,
                        'null' => FALSE,
                    ],
                    'name' => [
                        'type' => 'VARCHAR',
                        'constraint' => 150,
                        'null' => FALSE,
                    ],
                    'position' => [
                        'type' => 'VARCHAR',
                        'constraint' => 150,
                        'null' => FALSE,
                    ],
                    'signature_path' => [
                        'type' => 'VARCHAR',
                        'constraint' => 255,
                        'null' => FALSE,
                    ],
                    'field_x' => [
                        'type' => 'DECIMAL',
                        'constraint' => '5,2',
                        'null' => FALSE,
                        'default' => 24,
                    ],
                    'field_y' => [
                        'type' => 'DECIMAL',
                        'constraint' => '5,2',
                        'null' => FALSE,
                        'default' => 86,
                    ],
                    'font_size' => [
                        'type' => 'SMALLINT',
                        'unsigned' => TRUE,
                        'null' => FALSE,
                        'default' => 12,
                    ],
                    'alignment' => [
                        'type' => 'CHAR',
                        'constraint' => 1,
                        'null' => FALSE,
                        'default' => 'C',
                    ],
                    'is_required' => [
                        'type' => 'TINYINT',
                        'constraint' => 1,
                        'null' => FALSE,
                        'default' => 1,
                    ],
                    'is_active' => [
                        'type' => 'TINYINT',
                        'constraint' => 1,
                        'null' => FALSE,
                        'default' => 1,
                    ],
                    'created_by' => [
                        'type' => 'INT',
                        'unsigned' => TRUE,
                        'null' => TRUE,
                    ],
                    'created_at' => [
                        'type' => 'TIMESTAMP',
                        'default' => 'CURRENT_TIMESTAMP',
                        'null' => FALSE,
                    ],
                    'updated_at' => [
                        'type' => 'TIMESTAMP',
                        'default' => 'CURRENT_TIMESTAMP',
                        'null' => FALSE,
                    ],
                ])
                ->add_key('signatory_id', primary: TRUE)
                ->add_key('user_id', unique: TRUE, name: 'uq_certificate_signatory_user')
                ->add_key('is_active', name: 'idx_certificate_signatory_active')
                ->add_foreign_key('user_id', 'users', 'user_id', 'CASCADE', 'CASCADE')
                ->add_foreign_key('created_by', 'users', 'user_id', 'SET NULL', 'CASCADE')
                ->create_table('certificate_signatories');
        }

        if (!$this->_lava->dbforge->table_exists('certificate_signatures')) {
            $this->_lava->dbforge
                ->add_field([
                    'signature_id' => [
                        'type' => 'INT',
                        'unsigned' => TRUE,
                        'auto_increment' => TRUE,
                        'null' => FALSE,
                    ],
                    'certificate_id' => [
                        'type' => 'INT',
                        'unsigned' => TRUE,
                        'null' => FALSE,
                    ],
                    'signatory_id' => [
                        'type' => 'INT',
                        'unsigned' => TRUE,
                        'null' => FALSE,
                    ],
                    'user_id' => [
                        'type' => 'INT',
                        'unsigned' => TRUE,
                        'null' => FALSE,
                    ],
                    'status' => [
                        'type' => 'ENUM',
                        'constraint' => "'pending','signed','rejected'",
                        'null' => FALSE,
                        'default' => 'pending',
                    ],
                    'signature_path' => [
                        'type' => 'VARCHAR',
                        'constraint' => 255,
                        'null' => TRUE,
                    ],
                    'signatory_name' => [
                        'type' => 'VARCHAR',
                        'constraint' => 150,
                        'null' => FALSE,
                    ],
                    'position' => [
                        'type' => 'VARCHAR',
                        'constraint' => 150,
                        'null' => FALSE,
                    ],
                    'field_x' => [
                        'type' => 'DECIMAL',
                        'constraint' => '5,2',
                        'null' => FALSE,
                    ],
                    'field_y' => [
                        'type' => 'DECIMAL',
                        'constraint' => '5,2',
                        'null' => FALSE,
                    ],
                    'font_size' => [
                        'type' => 'SMALLINT',
                        'unsigned' => TRUE,
                        'null' => FALSE,
                    ],
                    'alignment' => [
                        'type' => 'CHAR',
                        'constraint' => 1,
                        'null' => FALSE,
                    ],
                    'is_required' => [
                        'type' => 'TINYINT',
                        'constraint' => 1,
                        'null' => FALSE,
                        'default' => 1,
                    ],
                    'signed_at' => [
                        'type' => 'DATETIME',
                        'null' => TRUE,
                    ],
                    'ip_address' => [
                        'type' => 'VARCHAR',
                        'constraint' => 45,
                        'null' => TRUE,
                    ],
                    'certificate_hash' => [
                        'type' => 'CHAR',
                        'constraint' => 64,
                        'null' => TRUE,
                    ],
                    'remarks' => [
                        'type' => 'TEXT',
                        'null' => TRUE,
                    ],
                    'created_at' => [
                        'type' => 'TIMESTAMP',
                        'default' => 'CURRENT_TIMESTAMP',
                        'null' => FALSE,
                    ],
                ])
                ->add_key('signature_id', primary: TRUE)
                ->add_key(['certificate_id', 'signatory_id'], unique: TRUE, name: 'uq_certificate_signature_slot')
                ->add_key(['user_id', 'status'], name: 'idx_certificate_signature_queue')
                ->add_foreign_key('certificate_id', 'certificates', 'certificate_id', 'CASCADE', 'CASCADE')
                ->add_foreign_key('signatory_id', 'certificate_signatories', 'signatory_id', 'RESTRICT', 'CASCADE')
                ->add_foreign_key('user_id', 'users', 'user_id', 'RESTRICT', 'CASCADE')
                ->create_table('certificate_signatures');
        }

        if (!$this->_lava->dbforge->table_exists('certificate_notifications')) {
            $this->_lava->dbforge
                ->add_field([
                    'notification_id' => [
                        'type' => 'INT',
                        'unsigned' => TRUE,
                        'auto_increment' => TRUE,
                        'null' => FALSE,
                    ],
                    'user_id' => [
                        'type' => 'INT',
                        'unsigned' => TRUE,
                        'null' => FALSE,
                    ],
                    'certificate_id' => [
                        'type' => 'INT',
                        'unsigned' => TRUE,
                        'null' => FALSE,
                    ],
                    'message' => [
                        'type' => 'VARCHAR',
                        'constraint' => 255,
                        'null' => FALSE,
                    ],
                    'read_at' => [
                        'type' => 'DATETIME',
                        'null' => TRUE,
                    ],
                    'created_at' => [
                        'type' => 'TIMESTAMP',
                        'default' => 'CURRENT_TIMESTAMP',
                        'null' => FALSE,
                    ],
                ])
                ->add_key('notification_id', primary: TRUE)
                ->add_key(['user_id', 'read_at'], name: 'idx_certificate_notification_user')
                ->add_foreign_key('user_id', 'users', 'user_id', 'CASCADE', 'CASCADE')
                ->add_foreign_key('certificate_id', 'certificates', 'certificate_id', 'CASCADE', 'CASCADE')
                ->create_table('certificate_notifications');
        }
    }

    public function down()
    {
        $this->_lava->dbforge->drop_table('certificate_notifications');
        $this->_lava->dbforge->drop_table('certificate_signatures');
        $this->_lava->dbforge->drop_table('certificate_signatories');
    }
}
