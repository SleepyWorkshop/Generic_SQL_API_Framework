<?php

require_once __DIR__ . '/SqlGenerator.php';
require_once __DIR__ . '/../../core/Response.php';
require_once __DIR__ . '/../../core/ExceptionHandler.php';
require_once __DIR__ . '/../../core/OperationalLogger.php';

class SqlParserRequestHandler
{
    public function handle(string $method, string $body, int $contentLength): array
    {
        $started = microtime(true);
        $logger = new OperationalLogger();
        $logger->info('sqlparser', 'SQL parser request started', [
            'method' => $method,
            'sql_length' => $contentLength,
        ]);
        if ($method !== 'POST') {
            $logger->warning('sqlparser', 'SQL parser request rejected', ['error_code' => 'METHOD_NOT_ALLOWED']);
            return [405, Response::errorPayload('Method not allowed.','METHOD_NOT_ALLOWED')];
        }
        if ($contentLength > 200000 || strlen($body) > 200000) {
            $logger->warning('sqlparser', 'SQL parser request rejected', ['error_code' => 'REQUEST_TOO_LARGE']);
            return [413, Response::errorPayload('Request body is too large.','REQUEST_TOO_LARGE')];
        }
        try { $input=json_decode($body,true,512,JSON_THROW_ON_ERROR); }
        catch(JsonException){
            $logger->warning('sqlparser', 'SQL parser request rejected', ['error_code' => 'INVALID_JSON']);
            return [400,Response::errorPayload('Invalid JSON request.','INVALID_JSON')];
        }
        if(!is_array($input)||array_keys($input)!==['sql']||!is_string($input['sql'])) {
            $logger->warning('sqlparser', 'SQL parser request rejected', ['error_code' => 'INVALID_REQUEST']);
            return [400,Response::errorPayload('Request must contain only a string sql property.','INVALID_REQUEST')];
        }
        try {
            $logger->info('sqlparser', 'SQL parsing started', [
                'sql_length' => strlen($input['sql']),
                'sql_hash' => hash('sha256', $input['sql']),
            ]);
            $result=(new SqlGenerator())->generate($input['sql']);
            $duration = round((microtime(true) - $started) * 1000, 2);
            if ($result['success']) $logger->info('sqlparser', 'SQL parsing successful', ['duration_ms' => $duration]);
            else $logger->warning('sqlparser', 'SQL parser validation failed', [
                'error_code' => $result['error']['code'] ?? 'SQL_GENERATION_FAILED',
                'duration_ms' => $duration,
            ]);
            return [$result['success']?200:422,$result];
        }
        catch(SqlParserException $exception){
            $sql=$input['sql'];
            $before=substr($sql,0,max(0,$exception->position));
            $line=substr_count($before,"\n")+1;
            $lastNewline=strrpos($before,"\n");
            $column=$exception->position-($lastNewline===false?-1:$lastNewline);
            $payload=Response::errorPayload('SQL parse error.','SQL_PARSE_ERROR',[['path'=>'sql','position'=>$exception->position,'line'=>$line,'column'=>$column,'message'=>$exception->getMessage()]]);
            $payload['analysis']=['pipeline'=>['parser'=>$exception->getMessage(),'capability'=>'Not started.','mapping'=>'Not started.','validation'=>'Not started.']];
            $payload['error']['stage']='parser';
            $logger->warning('sqlparser', 'SQL parsing failed', [
                'error_code' => 'SQL_PARSE_ERROR', 'line' => $line, 'column' => $column,
                'duration_ms' => round((microtime(true) - $started) * 1000, 2),
            ]);
            return [400,$payload];
        }
        catch(Throwable $exception){
            ExceptionHandler::report($exception,'sql_parser.exception');
            return [500,Response::errorPayload('SQL parser failed.','PARSER_ERROR')];
        }
    }
}
