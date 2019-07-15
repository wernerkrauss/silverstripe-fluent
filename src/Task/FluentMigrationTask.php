<?php


namespace TractorCow\Fluent\Task;


use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\BuildTask;
use SilverStripe\ORM\Connect\DatabaseException;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DB;
use TractorCow\Fluent\Extension\FluentExtension;
use TractorCow\Fluent\Extension\FluentVersionedExtension;

/**
 * Class FluentMigrationTask.
 *
 * Migrate content from Fluent 3.x format to 4.x
 *
 * THIS IS A WORK IN PROGRESS. USE AT YOUR OWN RISK.
 *
 * Assumptions:
 * - we still have the locales defined in Fluent.locales yml config, but we also have the locales defined in the db
 *
 * TODO:
 * - parameter for dry-run
 */
class FluentMigrationTask extends BuildTask
{

    protected $title = "Convert Fluent/SS3 > Fluent SS4 Task";

    protected $description = "Migrates site DB from SS3 Fluent DB format to SS4 Fluent.";

    private static $segment = 'FluentMigrationTask';


    /**
     * Parameters in order:
     *    - Localised table name with suffix
     *    - Version field selector (if 'Versions' table)
     *    - Field for record ID in source table (normally ID but RecordID in 'Versions' table)
     *    - Locale string
     *    - List of fields to select
     *    - Source table name
     *    - List of fields to update
     */
    const QUERY_TEMPLATE = "
        INSERT INTO `%s`
        SELECT
            %s
            NULL AS `ID`,
            `%s` AS `RecordID`,
            '%s' AS `Locale`,
            %s
        FROM `%s`
    ON DUPLICATE KEY UPDATE
        %s
    ;
    ";
    /**
     * List of suffixes to be applied to the base table names
     */
    const SUFFIXES = [
        'versioned' => [
            '',
            '_Live',
            '_Versions',
        ],
        'unversioned' => [
            ''
        ]

    ];
    protected $dryRun = false;
    protected $migrate_sublcasses_of = DataObject::class;

    /**
     * @param $request
     */
    public function run($request)
    {
        $queries = $this->buildQueries();
        $this->runQueries($queries);
    }

    /**
     * @param $tableFields
     * @return array
     * @throws \Exception
     */
    protected function buildQueries()
    {
        $queries = [];
        foreach ($this->getLocales() as $locale) {
            $queries[$locale] = $this->buildQueriesForLocale($locale);
        }
        return $queries;
    }

    /**
     * We assume that the old config is still available to get the configured locales
     *
     * @return array
     * @throws \Exception
     */
    public function getLocales()
    {
        $locales = Config::inst()->get('Fluent', 'locales');

        if (empty($locales)) {
            throw new \Exception('Fluent.locales is required');
        }

        return $locales;
    }

    /**
     * @param $tableFields
     * @param $locale
     * @return array
     */
    protected function buildQueriesForLocale($locale)
    {
        $localeQueries = [];
        $classes = $this->getFluentClasses();
        foreach ($classes as $class) {
            $currentInstance = singleton($class);
            $tableFields = $currentInstance->getLocalisedTables();

            $suffixes = $currentInstance->hasExtension(FluentVersionedExtension::class)
                ? self::SUFFIXES['versioned']
                : self::SUFFIXES['unversioned'];

            foreach ($tableFields as $table => $fields) {
                if (count($fields) > 0) {
                    foreach ($suffixes as $suffix) {
                        $localisedTable = "{$table}_Localised{$suffix}";
                        $sourceTable = "{$table}{$suffix}";
                        $selectFields = $this->buildSelectFieldList($fields, $locale);
                        $updateFields = $this->buildUpdateFieldList($fields, $locale);
                        if ($suffix === '_Versions') {
                            $versionSelector = 'Version,';
                            $idField = 'RecordID';
                        } else {
                            $versionSelector = '';
                            $idField = 'ID';
                        }
                        $localeQueries[$localisedTable] = sprintf(
                            self::QUERY_TEMPLATE,
                            $localisedTable,
                            $versionSelector,
                            $idField,
                            $locale,
                            $selectFields,
                            $sourceTable,
                            $updateFields
                        );
                    }
                }
            }
        }

        return $localeQueries;
    }

    /**
     * Get all sub-classes of DataObject that have FluentExtension applied
     *
     * @return array    Class names
     */
    protected function getFluentClasses()
    {
        $dataObjects = ClassInfo::subclassesFor($this->migrate_sublcasses_of);
        return array_filter(
            array_values($dataObjects),
            function ($className) {
                return call_user_func([$className, 'has_extension'], FluentExtension::class);
            }
        );
    }

    /**
     * @param $fields
     * @param $locale
     * @return string
     */
    protected function buildSelectFieldList($fields, $locale)
    {
        return implode(
            ', ',
            array_map(
                function ($field) use ($locale) {
                    return sprintf('`%s_%s` AS `%s`', $field, $locale, $field);
                },
                $fields
            )
        );
    }

    /**
     * @param $fields
     * @param $locale
     * @return string
     */
    protected function buildUpdateFieldList($fields, $locale)
    {
        return implode(
            ', ',
            array_map(
                function ($field) use ($locale) {
                    return sprintf('`%s` = `%s_%s`', $field, $field, $locale);
                },
                $fields
            )
        );
    }

    /**
     * @param $queries
     */
    protected function runQueries($queries)
    {
        foreach ($queries as $locale => $localeQueries) {
            echo "\nRunning queries for locale '{$locale}'\n\n";

            foreach ($localeQueries as $table => $query) {
                echo "Updating table '{$table}'\n";
                if ($this->dryRun === false) {
                    try {
                        DB::query($query);
                    } catch (DatabaseException $e) {
                        echo $e->getMessage();
                    }
                } else {
                    echo $query;
                }
            }
        }
    }

    /**
     * Setter for testing... or if you want to migrate only a specific dataobject
     *
     * @param string $dataobject
     */
    public function setMigrateSubclassesOf($dataobject)
    {
        $this->migrate_sublcasses_of = $dataobject;
        return $this;
    }
}