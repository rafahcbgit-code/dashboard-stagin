<?php

$entries = [];
foreach ([
    ['List available team members', 'get', '/api/agents', null,
        'Uses your organization API key. Returns active team members with their numeric id for user_id. No mobile login or organization header is needed.'],
    ['Assign or unassign a conversation', 'put', '/api/chats/{contact_uuid}/assign', ['user_id' => 5],
        'Uses your organization API key. Replace {contact_uuid} with the contact UUID from GET /api/contacts. Send user_id from GET /api/agents, or null to unassign. user_id must be explicitly present. Only contacts and active members of your organization are accepted. Returns success, message and data (contact_uuid, assigned_to). Errors: 401 invalid key, 404 contact not found, 422 invalid or missing user_id.'],
] as [$title, $method, $route, $payload, $description]) {
    $verb = strtoupper($method);
    $url = '{{base_url}}'.$route;
    $json = $payload === null ? null : json_encode($payload);
    $headers = ['Authorization' => 'Bearer <YOUR_ORGANIZATION_API_KEY>', 'Accept' => 'application/json', 'Content-Type' => 'application/json'];
    $headerJson = json_encode($headers, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    $entries[] = [
        'title' => $title, 'method' => $method, 'route' => $route, 'description' => $description,
        'request' => [
            'curl' => "curl -X {$verb} '{$url}' -H 'Authorization: Bearer <YOUR_ORGANIZATION_API_KEY>' -H 'Accept: application/json' -H 'Content-Type: application/json'".($json === null ? '' : " -d '{$json}'"),
            'php' => "<?php\n\$client = new \\GuzzleHttp\\Client();\n\$response = \$client->request('{$verb}', '{$url}', [\n    'headers' => ".var_export($headers, true).",".($payload === null ? '' : "\n    'json' => ".var_export($payload, true).',')."\n]);\necho \$response->getBody();",
            'nodejs' => "const response = await fetch('{$url}', {\n  method: '{$verb}',\n  headers: {$headerJson}".($json === null ? '' : ",\n  body: JSON.stringify({$json})")."\n});\nconsole.log(await response.json());",
            'python' => "import requests\n\nresponse = requests.request('{$verb}', '{$url}',\n    headers={$headerJson}".($json === null ? '' : ",\n    json={$json}")."\n)\nprint(response.json())",
            'java' => "OkHttpClient client = new OkHttpClient();\nRequest request = new Request.Builder()\n  .url(\"{$url}\")\n  .addHeader(\"Authorization\", \"Bearer <YOUR_ORGANIZATION_API_KEY>\")\n  .addHeader(\"Accept\", \"application/json\")\n  .method(\"{$verb}\", ".($json === null ? 'null' : 'RequestBody.create(MediaType.parse("application/json"), '.json_encode($json).')').")\n  .build();\nResponse response = client.newCall(request).execute();",
            'ruby' => "require 'net/http'\nrequire 'json'\nurl = URI('{$url}')\nrequest = Net::HTTP::".ucfirst($method).".new(url)\nrequest['Authorization'] = 'Bearer <YOUR_ORGANIZATION_API_KEY>'\nrequest['Accept'] = 'application/json'\nrequest['Content-Type'] = 'application/json'\n".($json === null ? '' : "request.body = '{$json}'\n")."response = Net::HTTP.start(url.host, url.port, use_ssl: url.scheme == 'https') { |http| http.request(request) }\nputs response.body",
        ],
    ];
}

return ['title' => 'Conversation assignment', 'value' => $entries];
