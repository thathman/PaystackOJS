<?php

/**
 * @file PaystackEmailDataMigration.php
 *
 * Copyright (c) 2025 Hendrix Nwaokolo, Airix Media
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class PaystackEmailDataMigration
 *
 * @brief Migrations for the plugin's email templates
 */

namespace APP\plugins\paymethod\paystack;

use Exception;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use PKP\db\XMLDAO;
use PKP\facades\Locale;

class PaystackEmailDataMigration extends Migration
{
    private PaystackPlugin $plugin;

    public function __construct(PaystackPlugin $plugin)
    {
        $this->plugin = $plugin;
    }

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $xmlDao = new XMLDAO();

        $templateFile = $this->plugin->getInstallEmailTemplatesFile();
        $data = $xmlDao->parseStruct($templateFile, ['email']);

        if (!isset($data['email'])) {
            throw new Exception('Unable to find <email> entries in ' . $templateFile);
        }

        $locales = json_decode(DB::table('site')->value('installed_locales') ?? '["en"]');
        if (!is_array($locales)) {
            $locales = ['en'];
        }
        if (!in_array('en', $locales, true)) {
            $locales[] = 'en';
        }

        // Install context-specific templates for all contexts.
        // OJS 3.5 uses `contexts`; OJS 3.3 uses `journals`.
        if (DB::getSchemaBuilder()->hasTable('contexts')) {
            $contextIds = DB::table('contexts')
                ->pluck('context_id')
                ->map(static fn ($id) => (int) $id)
                ->filter(static fn ($id) => $id > 0)
                ->values()
                ->all();
        } else {
            $contextIds = DB::table('journals')
                ->pluck('journal_id')
                ->map(static fn ($id) => (int) $id)
                ->filter(static fn ($id) => $id > 0)
                ->values()
                ->all();
        }

        foreach ($data['email'] as $entry) {
            $attrs = $entry['attributes'];
            $name = $attrs['name'] ?? null;
            $emailKey = $attrs['key'];

            if (!$name) {
                throw new Exception('Failed to install email template ' . $emailKey . ' due to missing name');
            }

            $previous = Locale::getMissingKeyHandler();
            Locale::setMissingKeyHandler(fn (string $key): string => '');

            $localizedDefaults = [];
            foreach ($locales as $locale) {
                $translatedName = $name ? __($name, [], $locale) : $attrs['key'];
                $subject = isset($attrs['subject']) ? __($attrs['subject'], [], $locale) : '';
                $body = isset($attrs['body']) ? __($attrs['body'], [], $locale) : '';

                // Fallback to English when target locale key is missing.
                if ($translatedName === '' || $translatedName === $name) {
                    $translatedName = $name ? __($name, [], 'en') : $attrs['key'];
                }
                if (($subject === '' || $subject === ($attrs['subject'] ?? null)) && isset($attrs['subject'])) {
                    $subject = __($attrs['subject'], [], 'en');
                }
                if (($body === '' || $body === ($attrs['body'] ?? null)) && isset($attrs['body'])) {
                    $body = __($attrs['body'], [], 'en');
                }

                // Last-resort fallback: keep template key literal so records are never blank.
                $translatedName = $translatedName !== '' ? $translatedName : $attrs['key'];
                $subject = $subject !== '' ? $subject : (string) ($attrs['subject'] ?? $attrs['key']);
                $body = $body !== '' ? $body : (string) ($attrs['body'] ?? '');

                // Insert or ignore default data (Global)
                DB::table('email_templates_default_data')->updateOrInsert(
                    ['email_key' => $emailKey, 'locale' => $locale],
                    [
                        'name' => $translatedName,
                        'subject' => $subject,
                        'body' => $body,
                    ]
                );
                $localizedDefaults[$locale] = [
                    'subject' => $subject,
                    'body' => $body,
                ];
            }

            Locale::setMissingKeyHandler($previous);

            // Save context templates where missing.
            foreach ($contextIds as $contextId) {
                $emailId = DB::table('email_templates')
                    ->where('context_id', '=', $contextId)
                    ->where('email_key', '=', $emailKey)
                    ->value('email_id');

                if (!$emailId) {
                    $emailId = DB::table('email_templates')->insertGetId([
                        'email_key' => $emailKey,
                        'context_id' => $contextId,
                        'alternate_to' => null,
                    ]);
                }

                foreach ($localizedDefaults as $locale => $payload) {
                    DB::table('email_templates_settings')->updateOrInsert(
                        [
                            'email_id' => $emailId,
                            'locale' => $locale,
                            'setting_name' => 'name',
                        ],
                        ['setting_value' => $translatedName]
                    );
                    DB::table('email_templates_settings')->updateOrInsert(
                        [
                            'email_id' => $emailId,
                            'locale' => $locale,
                            'setting_name' => 'subject',
                        ],
                        ['setting_value' => $payload['subject']]
                    );
                    DB::table('email_templates_settings')->updateOrInsert(
                        [
                            'email_id' => $emailId,
                            'locale' => $locale,
                            'setting_name' => 'body',
                        ],
                        ['setting_value' => $payload['body']]
                    );
                }
            }
        }
    }

    /**
     * Revers the migrations
     */
    public function down(): void
    {
        $xmlDao = new XMLDAO();
        $data = $xmlDao->parseStruct($this->plugin->getInstallEmailTemplatesFile(), ['email']);

        if (!isset($data['email'])) {
            return;
        }

        foreach ($data['email'] as $entry) {
            $attrs = $entry['attributes'];
            $emailKey = $attrs['key'];

            DB::table('email_templates_default_data')->where('email_key', $emailKey)->delete();
        }
    }
}
