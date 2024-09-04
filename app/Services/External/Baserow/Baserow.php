<?php

namespace App\Services\External\Baserow;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

class Baserow
{
    private string $remarketing_messages_url = 'https://baserow.contraplagasc.com/api/database/rows/table/1184/?user_field_names=true';
    private string $attachments_url = 'https://baserow.contraplagasc.com/api/database/rows/table/1185/?user_field_names=true';

    public function __construct(
        private readonly string $api_key,
    )
    {
    }


    /**
     * @throws ConnectionException
     * @throws RequestException
     */
    public function RemarketingMessages(): array
    {
        return Http::withHeaders([
            'Authorization' => 'Token ' . $this->api_key,
        ])->get($this->remarketing_messages_url)
            ->throw()
            ->collect('results')
            ->map(function ($message) {
                return [
                    'ID' => $message['ID'],
                    'Mensaje' => $message['Mensaje'],
                    'Ciclo' => $message['Ciclo'],
                    'Attachments' => $this->Attachments($message['Attachments']),
                ];
            })
            ->toArray();

    }

    private function Attachments(array $Attachments): array
    {
        if (!empty($Attachments)) {


            $filters = [
                "filter_type" => "AND",
                "filters" => [
                    [
                        "type" => "link_row_has",
                        "field" => "Mensajes de Remarketing",
                        "value" => "4"
                    ]
                ],
                "groups" => [
                ]
            ];

            $response = Http::withHeaders([
                'Authorization' => 'Token ' . $this->api_key,
            ])->get($this->attachments_url, [
                'filters' => json_encode($filters),
                'user_field_names' => true,
            ])
                ->collect('results')
                ->map(function ($attachment) {
                    return [
                        'ID' => $attachment['ID'],
                        'url' => $attachment['Attachment'][0]['url'],
                        'visible_name' => $attachment['Attachment'][0]['visible_name'],
                        'name' => $attachment['Attachment'][0]['name'],
                    ];
                })
                ->toArray();

            return $response;
        }

        return [];

    }
}
