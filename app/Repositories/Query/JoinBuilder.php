<?php

class JoinBuilder
{
    private MetadataRepository $metadataRepository;
    private $columnResolver;

    public function __construct(MetadataRepository $metadataRepository, callable $columnResolver)
    {
        $this->metadataRepository = $metadataRepository;
        $this->columnResolver = $columnResolver;
    }

    public function build(array $request): string
    {
        $sql = '';
        if (empty($request['joins'])) {
            return $sql;
        }
        foreach ($request['joins'] as $join) {
            $type = strtoupper($join['type']);
            if (!in_array($type, ['INNER', 'LEFT', 'RIGHT'])) {
                throw new Exception("Invalid JOIN type: {$type}");
            }
            if (!$this->metadataRepository->tableExists($join['table'])) {
                throw new Exception("Invalid JOIN table: {$join['table']}");
            }
            foreach (['left', 'right'] as $side) {
                $resolved = ($this->columnResolver)($join[$side]);
                $table = $resolved['table']
                    ?? ($side === 'left' ? $request['table'] : $join['table']);
                if (!$this->metadataRepository->columnExists($table, $resolved['column'])) {
                    throw new Exception("Invalid JOIN column: {$join[$side]}");
                }
            }
            $alias = !empty($join['alias']) ? ' ' . $join['alias'] : '';
            $source = isset($join['_source']) ? $join['_source']->renderFrom() : $join['table'] . $alias;
            $sql .= "
            {$type} JOIN {$source}
            ON {$join['left']} = {$join['right']}
        ";
        }
        return $sql;
    }
}
