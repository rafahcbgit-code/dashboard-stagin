<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\WebWidget;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class WebWidgetService
{
    public function defaults(): array
    {
        return [
            'logo' => null,
            'logo_url' => null,
            'whatsapp_phone' => '',
            'header_text' => 'Chat with us',
            'header_subtext' => 'We usually reply in a few minutes',
            'widget_text' => 'Hello! I would like to know more.',
            'button_text' => 'Start Chat',
            'widget_type' => 'start_button',
            'input_placeholder' => 'Type your message...',
            'button_color' => '#25D366',
            'header_color' => '#128C7E',
        ];
    }

    public function upsertForOrganization(Organization $organization, array $payload, ?UploadedFile $logo = null): WebWidget
    {
        $widget = WebWidget::firstOrNew(['organization_id' => $organization->id]);

        if ($logo !== null) {
            $payload['logo'] = $this->storeLogo($widget, $logo);
        }

        if (($payload['widget_type'] ?? $widget->widget_type ?? 'start_button') !== 'input_field') {
            $payload['input_placeholder'] = null;
        }

        $widget->fill($payload);
        $widget->organization()->associate($organization);
        $widget->save();

        return $widget->fresh();
    }

    public function resolveOrganization(string $organization): ?Organization
    {
        return Organization::query()
            ->where('identifier', $organization)
            ->orWhere('uuid', $organization)
            ->when(is_numeric($organization), function ($query) use ($organization) {
                $query->orWhere('id', (int) $organization);
            })
            ->first();
    }

    public function toPublicConfig(WebWidget $widget): array
    {
        $config = array_merge($this->defaults(), $widget->only([
            'logo',
            'whatsapp_phone',
            'header_text',
            'header_subtext',
            'widget_text',
            'button_text',
            'widget_type',
            'input_placeholder',
            'button_color',
            'header_color',
        ]));

        $config['logo_url'] = $widget->logo ? url('/media/'.$widget->logo) : null;

        return $config;
    }

    public function script(): string
    {
        return <<<'JS'
(function () {
    'use strict';

    var scriptTag = document.currentScript;
    if (!scriptTag) {
        return;
    }

    var org = scriptTag.getAttribute('data-org');
    if (!org) {
        console.warn('[WebWidget] Missing data-org attribute.');
        return;
    }

    var scriptUrl = new URL(scriptTag.src, window.location.href);
    var baseUrl = scriptUrl.origin;
    var endpoint = baseUrl + '/widget/' + encodeURIComponent(org);

    fetch(endpoint, {
        method: 'GET',
        headers: {
            'Accept': 'application/json'
        }
    })
        .then(function (response) {
            if (!response.ok) {
                throw new Error('Unable to load widget config.');
            }
            return response.json();
        })
        .then(function (payload) {
            if (!payload || !payload.widget) {
                throw new Error('Invalid widget config.');
            }
            mountWidget(payload.widget);
        })
        .catch(function (error) {
            console.warn('[WebWidget]', error.message);
        });

    function mountWidget(config) {
        var host = document.createElement('div');
        host.setAttribute('data-web-widget', String(org));
        document.body.appendChild(host);

        var shadow = host.attachShadow({ mode: 'open' });
        var wrapper = document.createElement('div');
        wrapper.className = 'cb-widget';

        var phone = String(config.whatsapp_phone || '').replace(/\D+/g, '');
        if (!phone) {
            return;
        }

        var buttonText = config.button_text || 'Start Chat';
        var headerText = config.header_text || 'Chat with us';
        var headerSubtext = config.header_subtext || 'We usually reply in a few minutes';
        var widgetType = config.widget_type === 'input_field' ? 'input_field' : 'start_button';
        var buttonColor = config.button_color || '#25D366';
        var headerColor = config.header_color || '#128C7E';
        var placeholder = config.input_placeholder || 'Type your message...';
        var defaultMessage = config.widget_text || '';
        var logoUrl = config.logo_url || '';

        var style = document.createElement('style');
        style.textContent = [
            '.cb-widget {',
            'position: fixed;',
            'right: 16px;',
            'bottom: 16px;',
            'z-index: 2147483000;',
            'width: min(340px, calc(100vw - 24px));',
            'font-family: "Segoe UI", Tahoma, sans-serif;',
            '}',
            '.cb-card { background: #fff; border-radius: 12px; box-shadow: 0 12px 30px rgba(0,0,0,0.18); overflow: hidden; border: 1px solid #e5e7eb; }',
            '.cb-header { padding: 12px; color: #fff; display: flex; gap: 10px; align-items: center; }',
            '.cb-logo { width: 34px; height: 34px; object-fit: cover; border-radius: 50%; background: rgba(255,255,255,0.9); }',
            '.cb-title { font-size: 14px; font-weight: 600; line-height: 1.2; margin: 0; }',
            '.cb-subtitle { font-size: 12px; opacity: 0.95; margin: 2px 0 0; line-height: 1.2; }',
            '.cb-body { padding: 12px; }',
            '.cb-message { font-size: 13px; color: #334155; margin: 0 0 10px; line-height: 1.4; }',
            '.cb-row { display: flex; gap: 8px; }',
            '.cb-input { flex: 1; border: 1px solid #cbd5e1; border-radius: 8px; padding: 10px; font-size: 13px; outline: none; min-width: 0; }',
            '.cb-input:focus { border-color: #94a3b8; }',
            '.cb-button { border: 0; border-radius: 8px; color: #fff; padding: 10px 12px; font-size: 13px; font-weight: 600; cursor: pointer; width: 100%; }',
            '.cb-button-inline { width: auto; white-space: nowrap; }',
            '@media (max-width: 520px) {',
            '.cb-widget { right: 10px; bottom: 10px; width: calc(100vw - 20px); }',
            '.cb-row { flex-direction: column; }',
            '.cb-button-inline { width: 100%; }',
            '}'
        ].join('');

        var card = document.createElement('div');
        card.className = 'cb-card';

        var header = document.createElement('div');
        header.className = 'cb-header';
        header.style.background = headerColor;

        if (logoUrl) {
            var logo = document.createElement('img');
            logo.className = 'cb-logo';
            logo.src = logoUrl;
            logo.alt = 'Widget logo';
            header.appendChild(logo);
        }

        var titleWrap = document.createElement('div');
        var title = document.createElement('p');
        title.className = 'cb-title';
        title.textContent = headerText;
        var subtitle = document.createElement('p');
        subtitle.className = 'cb-subtitle';
        subtitle.textContent = headerSubtext;
        titleWrap.appendChild(title);
        titleWrap.appendChild(subtitle);
        header.appendChild(titleWrap);

        var body = document.createElement('div');
        body.className = 'cb-body';

        if (defaultMessage) {
            var message = document.createElement('p');
            message.className = 'cb-message';
            message.textContent = defaultMessage;
            body.appendChild(message);
        }

        var row = document.createElement('div');
        row.className = 'cb-row';

        if (widgetType === 'input_field') {
            var input = document.createElement('input');
            input.className = 'cb-input';
            input.type = 'text';
            input.placeholder = placeholder;
            row.appendChild(input);

            var inputButton = document.createElement('button');
            inputButton.className = 'cb-button cb-button-inline';
            inputButton.style.background = buttonColor;
            inputButton.textContent = buttonText;
            inputButton.addEventListener('click', function () {
                openWhatsapp(phone, input.value || defaultMessage);
            });

            row.appendChild(inputButton);
        } else {
            var button = document.createElement('button');
            button.className = 'cb-button';
            button.style.background = buttonColor;
            button.textContent = buttonText;
            button.addEventListener('click', function () {
                openWhatsapp(phone, defaultMessage);
            });

            row.appendChild(button);
        }

        body.appendChild(row);
        card.appendChild(header);
        card.appendChild(body);
        wrapper.appendChild(card);
        shadow.appendChild(style);
        shadow.appendChild(wrapper);
    }

    function openWhatsapp(phone, message) {
        var text = (message || '').trim();
        var chatUrl = 'https://wa.me/' + encodeURIComponent(phone) + '?text=' + encodeURIComponent(text);
        window.open(chatUrl, '_blank', 'noopener,noreferrer');
    }
})();
JS;
    }

    private function storeLogo(WebWidget $widget, UploadedFile $logo): string
    {
        $path = $logo->store('public/web-widgets/logos');

        if ($widget->logo && Storage::exists($widget->logo)) {
            Storage::delete($widget->logo);
        }

        return $path;
    }
}
