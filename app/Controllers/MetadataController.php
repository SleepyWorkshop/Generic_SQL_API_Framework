<?php

require_once __DIR__ . '/BaseController.php';
require_once __DIR__ . '/../Services/MetadataService.php';
require_once __DIR__ . '/../Database/DatabaseDirectory.php';

class MetadataController extends BaseController
{
    private ?MetadataService $metadataService = null;

    /** Created on first use: the database listing never opens a connection. */
    private function metadata(): MetadataService
    {
        return $this->metadataService ??= new MetadataService();
    }

    /**
     * Logical databases from the registry; no catalog query or connection.
     */
    public function databases($request)
    {
        $databases = (new DatabaseDirectory())->databases();
        $this->success(
            ['rowsReturned' => count($databases), 'data' => $databases],
            "Databases Loaded Successfully"
        );
    }

    /**
     * Get Tables
     */
    public function tables($request)
    {
        $this->success(
            $this->metadata()->getTables(),
            "Tables Loaded Successfully"
        );
    }

    /**
     * Get Columns
     */
    public function columns($request)
    {
        Validator::required($request, [
            'table'
        ]);

        $this->success(
            $this->metadata()->getColumns(
                $request['table'],
                $request['schema'] ?? null
            ),
            "Columns Loaded Successfully"
        );
    }

    /**
    * Get Views
    */
    public function views($request)
    {
        $this->success(
           $this->metadata()->getViews(),
        "Views Loaded Successfully"
           );
    }

    /**
    * Get Stored Procedures
    */
    public function procedures($request)
    {
        $this->success(
           $this->metadata()->getProcedures(),
        "Stored Procedures Loaded Successfully"
           );
    }

    /**
    * Get Schema
    */
    public function schema($request)
    {
        $this->success(
           $this->metadata()->schema(),
        "Schema Loaded Successfully"
           );
    }
}