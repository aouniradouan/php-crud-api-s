<?php
namespace Tqdev\PhpCrudApi\GeoJson;

use Tqdev\PhpCrudApi\Column\ReflectionService;
use Tqdev\PhpCrudApi\GeoJson\FeatureCollection;
use Tqdev\PhpCrudApi\Record\RecordService;

class GeoJsonService
{
    private $reflection;
    private $records;

    public function __construct(ReflectionService $reflection, RecordService $records)
    {
        $this->reflection = $reflection;
        $this->records = $records;
    }

    public function hasTable(string $table): bool
    {
        return $this->reflection->hasTable($table);
    }

    public function getType(string $table): string
    {
        return $this->reflection->getType($table);
    }

    private function getGeometryColumnName(string $tableName, string $geometryParam): string
    {
        $table = $this->reflection->getTable($tableName);
        foreach ($table->getColumnNames() as $columnName) {
            if ($geometryParam && $geometryParam != $columnName) {
                continue;
            }
            $column = $table->getColumn($columnName);
            if ($column->isGeometry()) {
                return $columnName;
            }
        }
        return "";
    }

    private function convertRecordToFeature( /*object*/$record, string $geometryColumnName)
    {
        $geometry = Geometry::fromWkt($record[$geometryColumnName]);
        unset($record[$geometryColumnName]);
        return new Feature($record, $geometry);
    }

    public function _list(string $tableName, array $params): FeatureCollection
    {
        $geometryParam = isset($params['geometry']) ? $params['geometry'] : '';
        $geometryColumnName = $this->getGeometryColumnName($tableName, $geometryParam);
        $records = $this->records->_list($tableName, $params);

        $features = array();
        foreach ($records->getRecords() as $record) {
            if (isset($record[$geometryColumnName])) {
                $features[] = $this->convertRecordToFeature($record, $geometryColumnName);
            }
        }
        return new FeatureCollection($features);
    }

    public function read(string $tableName, string $id, array $params) /*: ?Feature*/
    {
        $geometryParam = isset($params['geometry']) ? $params['geometry'] : '';
        $geometryColumnName = $this->getGeometryColumnName($tableName, $geometryParam);
        $record = $this->records->read($tableName, $id, $params);
        if (!isset($record[$geometryColumnName])) {
            print_r($record);
            return null;
        }
        return $this->convertRecordToFeature($record, $geometryColumnName);
    }
}
