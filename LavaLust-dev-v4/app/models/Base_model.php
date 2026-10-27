<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Base_model
{
    protected $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function rows($sql, array $values = [])
    {
        return $this->db->raw($sql, $values)->fetchAll(PDO::FETCH_ASSOC);
    }

    public function row($sql, array $values = [])
    {
        $record = $this->db->raw($sql, $values)->fetch(PDO::FETCH_ASSOC);
        return $record ?: null;
    }

    public function execute($sql, array $values = [])
    {
        return $this->db->raw($sql, $values)->rowCount();
    }
}
