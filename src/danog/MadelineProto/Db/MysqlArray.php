<?php

namespace danog\MadelineProto\Db;

use Amp\Mysql\Pool;
use Amp\Sql\ResultSet;
use danog\MadelineProto\Tools;
use function Amp\call;

class MysqlArray implements DbArray
{
    private string $table;
    private array $settings;
    private Pool $db;
    private ?string $key = null;
    private $current;

    public function __serialize(): array
    {
        return [
            'table' => $this->table,
            'settings' => $this->settings
        ];
    }

    public function __unserialize($data): void
    {
        foreach ($data as $property => $value) {
            $this->{$property} = $value;
        }
        $this->initDbConnection();
    }

    public static function getInstance(array $settings, string $name, $value = []): DbType
    {
        $instance = new static();
        $instance->table = $name;
        $instance->settings = $settings['mysql'];
        $instance->initDbConnection();
        $instance->prepareTable();

        if (!empty($value) && !$value instanceof static) {
            if ($value instanceof DbArray) {
                $value = $value->getArrayCopy();
            }
            foreach ((array) $value as $key => $item) {
                $instance[$key] = $item;
            }
        }


        return $instance;
    }

    /**
     * Check if offset exists
     *
     * @link https://php.net/manual/en/arrayiterator.offsetexists.php
     *
     * @param string $index <p>
     * The offset being checked.
     * </p>
     *
     * @return bool true if the offset exists, otherwise false
     * @throws \Throwable
     */
    public function offsetExists($index)
    {
        $row = $this->syncRequest(
            "SELECT count(`key`) as `count` FROM {$this->table} WHERE `key` = :index LIMIT 1",
            ['index' => $index]
        );

        return !empty($row[0]['count']);
    }

    /**
     * Get value for an offset
     *
     * @link https://php.net/manual/en/arrayiterator.offsetget.php
     *
     * @param string $index <p>
     * The offset to get the value from.
     * </p>
     *
     * @return mixed The value at offset <i>index</i>.
     * @throws \Throwable
     */
    public function offsetGet($index)
    {
        $row = $this->syncRequest(
            "SELECT `value` FROM {$this->table} WHERE `key` = :index LIMIT 1",
            ['index' => $index]
        );
        return $this->getValue($row);

    }

    /**
     * Set value for an offset
     *
     * @link https://php.net/manual/en/arrayiterator.offsetset.php
     *
     * @param string $index <p>
     * The index to set for.
     * </p>
     * @param $value
     *
     * @return void
     * @throws \Throwable
     */
    public function offsetSet($index, $value)
    {
        $this->syncRequest("
                INSERT INTO `{$this->table}` 
                SET `key` = :index, `value` = :value 
                ON DUPLICATE KEY UPDATE `value` = :value
            ",
            [
                'index' => $index,
                'value' => serialize($value),
            ]
        );
    }

    /**
     * Unset value for an offset
     *
     * @link https://php.net/manual/en/arrayiterator.offsetunset.php
     *
     * @param string $index <p>
     * The offset to unset.
     * </p>
     *
     * @return void
     * @throws \Throwable
     */
    public function offsetUnset($index)
    {
        $this->syncRequest("
                    DELETE FROM `{$this->table}`
                    WHERE `key` = :index
                ",
            ['index' => $index]
        );
    }

    /**
     * Get array copy
     *
     * @link https://php.net/manual/en/arrayiterator.getarraycopy.php
     * @return array A copy of the array, or array of public properties
     * if ArrayIterator refers to an object.
     * @throws \Throwable
     */
    public function getArrayCopy(): array
    {
        $rows = $this->syncRequest("SELECT `key`, `value` FROM {$this->table}");
        $result = [];
        foreach ($rows as $row) {
            $result[$row['key']] = unserialize($row['value']);
        }

        return $result;
    }

    /**
     * Count elements
     *
     * @link https://php.net/manual/en/arrayiterator.count.php
     * @return int The number of elements or public properties in the associated
     * array or object, respectively.
     * @throws \Throwable
     */
    public function count(): int
    {
        $row = $this->syncRequest("SELECT count(`key`) as `count` FROM {$this->table}");
        return $row[0]['count'] ?? 0;
    }

    /**
     * Rewind array back to the start
     *
     * @link https://php.net/manual/en/arrayiterator.rewind.php
     * @return void
     * @throws \Throwable
     */
    public function rewind()
    {
        $this->key = null;
        $this->key();
        $this->current = null;
    }

    /**
     * Return current array entry
     *
     * @link https://php.net/manual/en/arrayiterator.current.php
     * @return mixed The current array entry.
     * @throws \Throwable
     */
    public function current()
    {
        return $this->current ?: $this->offsetGet($this->key());
    }

    private function getValue(array $row)
    {
        if ($row) {
            $row = reset($row);
            return unserialize($row['value']);
        }
        return null;
    }

    /**
     * Return current array key
     *
     * @link https://php.net/manual/en/arrayiterator.key.php
     * @return string|float|int|bool|null The current array key.
     * @throws \Throwable
     */
    public function key(): ?string
    {
        if ($this->key === null) {
            $row = $this->syncRequest(
                "SELECT `key` FROM {$this->table} ORDER BY `key` LIMIT 1"
            );
            $this->key = $row[0]['key'] ?? null;
        }
        return $this->key;
    }

    /**
     * Move to next entry
     *
     * @link https://php.net/manual/en/arrayiterator.next.php
     * @return void
     * @throws \Throwable
     */
    public function next()
    {
        $row = $this->syncRequest(
            "SELECT `key`, `value` FROM {$this->table} WHERE `key` > :key ORDER BY `key` LIMIT 1",
            ['key' => $this->key()]
        );

        $this->key = $row[0]['key'] ?? null;
        $this->current = $this->getValue($row);
    }

    /**
     * Check whether array contains more entries
     *
     * @link https://php.net/manual/en/arrayiterator.valid.php
     * @return bool
     * @throws \Throwable
     */
    public function valid():bool
    {
        return $this->key !== null;
    }

    /**
     * Seek to position
     * @link https://php.net/manual/en/arrayiterator.seek.php
     * @param int $position <p>
     * The position to seek to.
     * </p>
     * @return void
     */
    public function seek($position)
    {
        $row = $this->syncRequest(
            "SELECT `key` FROM {$this->table} ORDER BY `key` LIMIT 1, :position",
            ['offset' => $position]
        );
        $this->key = $row[0]['key'] ?? $this->key;
    }

    private function initDbConnection()
    {
        //TODO Use MtProto::$settings
        $this->db = Mysql::getConnection(
            $this->settings['host'],
            $this->settings['port'],
            $this->settings['user'],
            $this->settings['password'],
            $this->settings['database'],
        );
    }

    /**
     * Create table for property
     *
     * @return array|null
     * @throws \Throwable
     */
    private function prepareTable()
    {
        return $this->syncRequest("
            CREATE TABLE IF NOT EXISTS `{$this->table}`
            (
                `key` VARCHAR(255) NOT NULL,
                `value` MEDIUMBLOB NULL,
                `ts` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`key`)
            )
        ");
    }

    /**
     * Perform blocking request to db
     *
     * @param string $query
     * @param array $params
     *
     * @return array|null
     * @throws \Throwable
     */
    private function syncRequest(string $query, array $params = []): array
    {
        return Tools::wait(
            call(
                function() use($query, $params) {
                    $request = yield $this->db->execute($query, $params);
                    $result = [];
                    if ($request instanceof ResultSet) {
                        while (yield $request->advance()) {
                            $result[] = $request->getCurrent();
                        }
                    }
                    return $result;
                }
            )
        );
    }
}