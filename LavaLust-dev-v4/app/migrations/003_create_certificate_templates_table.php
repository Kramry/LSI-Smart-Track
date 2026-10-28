<?php

class Create_certificate_templates_table
{
    private $_lava;

    public function __construct()
    {
        $this->_lava = lava_instance();
        $this->_lava->call->dbforge();
    }

    public function up()
    {
        if (!$this->_lava->dbforge->table_exists('certificate_templates')) {
            $this->_lava->dbforge
                ->add_field([
                    'template_id' => [
                        'type' => 'INT',
                        'unsigned' => TRUE,
                        'auto_increment' => TRUE,
                        'null' => FALSE,
                    ],
                    'version' => [
                        'type' => 'INT',
                        'unsigned' => TRUE,
                        'null' => FALSE,
                    ],
                    'mode' => [
                        'type' => 'ENUM',
                        'constraint' => "'upload','generate'",
                        'null' => FALSE,
                    ],
                    'file_path' => [
                        'type' => 'VARCHAR',
                        'constraint' => 255,
                        'null' => TRUE,
                    ],
                    'file_mime' => [
                        'type' => 'VARCHAR',
                        'constraint' => 100,
                        'null' => TRUE,
                    ],
                    'field_layout' => [
                        'type' => 'JSON',
                        'null' => FALSE,
                    ],
                    'settings' => [
                        'type' => 'JSON',
                        'null' => FALSE,
                    ],
                    'is_active' => [
                        'type' => 'TINYINT',
                        'constraint' => 1,
                        'default' => 0,
                        'null' => FALSE,
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
                ])
                ->add_key('template_id', primary: TRUE)
                ->add_key('version', unique: TRUE, name: 'uq_certificate_template_version')
                ->add_key('is_active', name: 'idx_certificate_template_active')
                ->add_foreign_key('created_by', 'users', 'user_id', 'SET NULL', 'CASCADE')
                ->create_table('certificate_templates');
        }

        if ($this->_lava->dbforge->table_exists('certificates')
            && !$this->_lava->dbforge->column_exists('certificates', 'template_id')) {
            $this->_lava->dbforge->add_column('certificates', [
                'template_id' => [
                    'type' => 'INT',
                    'unsigned' => TRUE,
                    'null' => TRUE,
                ],
            ]);
        }
    }

    public function down()
    {
        if ($this->_lava->dbforge->table_exists('certificates')
            && $this->_lava->dbforge->column_exists('certificates', 'template_id')) {
            $this->_lava->dbforge->drop_column('certificates', 'template_id');
        }
        $this->_lava->dbforge->drop_table('certificate_templates');
    }
}
