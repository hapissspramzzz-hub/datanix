<?php
declare(strict_types=1);
return [
 'provider'=>getenv('AI_PROVIDER')?:'openai-compatible',
 'api_base'=>rtrim(getenv('AI_API_BASE')?:getenv('OPENAI_API_BASE')?:'','/'),
 'api_key'=>getenv('AI_API_KEY')?:getenv('OPENAI_API_KEY')?:'',
 'model'=>getenv('AI_MODEL')?:getenv('OPENAI_MODEL')?:'gpt-5-mini',
 'timeout_seconds'=>max(5,min(60,(int)(getenv('AI_TIMEOUT_SECONDS')?:25))),
];
