<?php
declare(strict_types=1);

/**
 * File: core/WhatsAppTemplates.php
 * Location: core/WhatsAppTemplates.php
 *
 * WinABN WhatsApp Message Templates
 *
 * Manages WhatsApp Business API message templates with variable substitution
 * and template validation for the WinABN platform.
 *
 * @package WinABN\Core
 * @author WinABN Development Team
 * @version 1.0
 */

namespace WinABN\Core;

use Exception;

class WhatsAppTemplates
{
    /**
     * Template definitions with Meta Business API format
     *
     * @var array<string, array>
     */
    private static array $templates = [

        /**
         * Winner Notification Template
         * Sent when a participant wins a prize
         */
        'winner_notification' => [
            'name' => 'winabn_winner_notification',
            'category' => 'TRANSACTIONAL',
            'language' => 'en_GB',
            'header' => [
                'type' => 'TEXT',
                'text' => '🎉 CONGRATULATIONS! 🎉'
            ],
            'body' => [
                'text' => "Hi {{1}}!\n\nFantastic news! You've WON {{2}}! 🏆\n\nYou were the fastest to answer all questions correctly. Your prize is ready to be claimed.\n\n👉 Claim your prize now: {{3}}\n\n⚠️ Important: You have 30 days to claim your prize using the secure link above.\n\nWell done and enjoy your prize! 🎁"
            ],
            'footer' => [
                'text' => 'WinABN - Fair Play Guaranteed'
            ],
            'variables' => [
                'first_name',     // {{1}}
                'prize_name',     // {{2}}
                'claim_url'       // {{3}}
            ],
            'buttons' => [
                [
                    'type' => 'URL',
                    'text' => 'Claim Prize',
                    'url' => '{{3}}'
                ]
            ]
        ],

        /**
         * Non-Winner Notification Template
         * Sent to participants who didn't win with replay offer
         */
        'non_winner_notification' => [
            'name' => 'winabn_try_again',
            'category' => 'MARKETING',
            'language' => 'en_GB',
            'header' => [
                'type' => 'TEXT',
                'text' => '🎯 So Close!'
            ],
            'body' => [
                'text' => "Hi {{1}}!\n\nThanks for playing! While you didn't win this time, you were so close! 💪\n\nWant another shot? We've got more exciting prizes waiting!\n\n🎁 Try again and WIN BIG: {{2}}\n\n✨ New games start every day\n🚀 Better odds, bigger prizes\n⚡ Just answer 9 quick questions\n\nYour next win could be just one game away!"
            ],
            'footer' => [
                'text' => 'Reply STOP to unsubscribe'
            ],
            'variables' => [
                'first_name',     // {{1}}
                'replay_url'      // {{2}}
            ],
            'buttons' => [
                [
                    'type' => 'URL',
                    'text' => 'Play Again',
                    'url' => '{{2}}'
                ]
            ]
        ],

        /**
         * Weekly Promotion Template
         * Sent every Friday with new games
         */
        'weekly_promotion' => [
            'name' => 'winabn_weekly_games',
            'category' => 'MARKETING',
            'language' => 'en_GB',
            'header' => [
                'type' => 'TEXT',
                'text' => '🔥 NEW WEEK, NEW PRIZES!'
            ],
            'body' => [
                'text' => "Hi {{1}}!\n\nThis week's AMAZING prizes are here! 🎉\n\n{{2}}\n\n🎯 How it works:\n✅ Answer 9 quick questions\n✅ Fastest correct completion wins\n✅ Fair play guaranteed\n\n🚀 Ready to win? Start playing: {{3}}\n\nDon't miss out - some prizes fill up fast!"
            ],
            'footer' => [
                'text' => 'Reply STOP to unsubscribe'
            ],
            'variables' => [
                'first_name',     // {{1}}
                'games_list',     // {{2}}
                'website_url'     // {{3}}
            ],
            'buttons' => [
                [
                    'type' => 'URL',
                    'text' => 'View Prizes',
                    'url' => '{{3}}'
                ]
            ]
        ],

        /**
         * Prize Claim Reminder Template
         * Sent 7 days after winning if prize not claimed
         */
        'claim_reminder' => [
            'name' => 'winabn_claim_reminder',
            'category' => 'TRANSACTIONAL',
            'language' => 'en_GB',
            'header' => [
                'type' => 'TEXT',
                'text' => '⏰ Prize Claim Reminder'
            ],
            'body' => [
                'text' => "Hi {{1}}!\n\nJust a friendly reminder that your prize {{2}} is still waiting to be claimed! 🎁\n\nYou have {{3}} days left to claim your prize.\n\n👉 Claim now: {{4}}\n\nDon't let your amazing prize slip away!"
            ],
            'footer' => [
                'text' => 'WinABN - Your prize awaits!'
            ],
            'variables' => [
                'first_name',     // {{1}}
                'prize_name',     // {{2}}
                'days_left',      // {{3}}
                'claim_url'       // {{4}}
            ],
            'buttons' => [
                [
                    'type' => 'URL',
                    'text' => 'Claim Prize',
                    'url' => '{{4}}'
                ]
            ]
        ],

        /**
         * Prize Shipped Notification Template
         * Sent when prize is shipped with tracking
         */
        'prize_shipped' => [
            'name' => 'winabn_prize_shipped',
            'category' => 'TRANSACTIONAL',
            'language' => 'en_GB',
            'header' => [
                'type' => 'TEXT',
                'text' => '📦 Your Prize is On Its Way!'
            ],
            'body' => [
                'text' => "Hi {{1}}!\n\nGreat news! Your prize {{2}} has been shipped! 🚚\n\n📋 Tracking Details:\nTracking Number: {{3}}\nEstimated Delivery: {{4}}\n\n👉 Track your package: {{5}}\n\nYour prize should arrive soon. Enjoy!"
            ],
            'footer' => [
                'text' => 'WinABN - Delivered with care'
            ],
            'variables' => [
                'first_name',     // {{1}}
                'prize_name',     // {{2}}
                'tracking_number', // {{3}}
                'delivery_date',  // {{4}}
                'tracking_url'    // {{5}}
            ],
            'buttons' => [
                [
                    'type' => 'URL',
                    'text' => 'Track Package',
                    'url' => '{{5}}'
                ]
            ]
        ],

        /**
         * Referral Success Template
         * Sent when someone successfully refers a friend
         */
        'referral_success' => [
            'name' => 'winabn_referral_success',
            'category' => 'MARKETING',
            'language' => 'en_GB',
            'header' => [
                'type' => 'TEXT',
                'text' => '🤝 Referral Success!'
            ],
            'body' => [
                'text' => "Hi {{1}}!\n\nAwesome! Your friend just played thanks to your referral! 🎉\n\nYou've both earned a 10% discount on your next game!\n\n💰 Your discount is ready to use\n🎯 Valid for 6 months\n🚀 Use it on any game\n\n👉 Play now with discount: {{2}}\n\nKeep referring friends for more rewards!"
            ],
            'footer' => [
                'text' => 'WinABN - Sharing is caring!'
            ],
            'variables' => [
                'first_name',     // {{1}}
                'play_url'        // {{2}}
            ],
            'buttons' => [
                [
                    'type' => 'URL',
                    'text' => 'Use Discount',
                    'url' => '{{2}}'
                ]
            ]
        ]
    ];

    /**
     * Get template by name
     *
     * @param string $templateName Template name
     * @return array<string, mixed>|null Template data or null if not found
     */
    public static function getTemplate(string $templateName): ?array
    {
        return self::$templates[$templateName] ?? null;
    }

    /**
     * Get all available templates
     *
     * @return array<string, array>
     */
    public static function getAllTemplates(): array
    {
        return self::$templates;
    }

    /**
     * Validate template variables
     *
     * @param string $templateName Template name
     * @param array<string, mixed> $variables Variables to validate
     * @return array<string, mixed> Validation result
     */
    public static function validateVariables(string $templateName, array $variables): array
    {
        $template = self::getTemplate($templateName);

        if (!$template) {
            return [
                'valid' => false,
                'error' => "Template '$templateName' not found"
            ];
        }

        $requiredVariables = $template['variables'] ?? [];
        $missingVariables = [];
        $extraVariables = [];

        // Check for missing required variables
        foreach ($requiredVariables as $required) {
            if (!isset($variables[$required]) || $variables[$required] === '') {
                $missingVariables[] = $required;
            }
        }

        // Check for extra variables
        foreach (array_keys($variables) as $provided) {
            if (!in_array($provided, $requiredVariables)) {
                $extraVariables[] = $provided;
            }
        }

        $isValid = empty($missingVariables);

        return [
            'valid' => $isValid,
            'missing_variables' => $missingVariables,
            'extra_variables' => $extraVariables,
            'error' => $isValid ? null : 'Missing required variables: ' . implode(', ', $missingVariables)
        ];
    }

    /**
     * Render template with variables (for preview)
     *
     * @param string $templateName Template name
     * @param array<string, mixed> $variables Template variables
     * @return array<string, mixed> Rendered template or error
     */
    public static function renderTemplate(string $templateName, array $variables): array
    {
        $template = self::getTemplate($templateName);

        if (!$template) {
            return [
                'success' => false,
                'error' => "Template '$templateName' not found"
            ];
        }

        // Validate variables
        $validation = self::validateVariables($templateName, $variables);
        if (!$validation['valid']) {
            return [
                'success' => false,
                'error' => $validation['error']
            ];
        }

        try {
            // Render body text
            $bodyText = $template['body']['text'];
            $headerText = $template['header']['text'] ?? '';
            $footerText = $template['footer']['text'] ?? '';

            // Replace variables ({{1}}, {{2}}, etc.)
            $variableIndex = 1;
            foreach ($template['variables'] as $variableName) {
                $placeholder = '{{' . $variableIndex . '}}';
                $value = (string) ($variables[$variableName] ?? '');

                $bodyText = str_replace($placeholder, $value, $bodyText);
                $headerText = str_replace($placeholder, $value, $headerText);
                $footerText = str_replace($placeholder, $value, $footerText);

                $variableIndex++;
            }

            return [
                'success' => true,
                'rendered' => [
                    'header' => $headerText,
                    'body' => $bodyText,
                    'footer' => $footerText,
                    'template_name' => $templateName,
                    'language' => $template['language']
                ]
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Template rendering failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Generate WhatsApp API payload for template
     *
     * @param string $phoneNumber Recipient phone number
     * @param string $templateName Template name
     * @param array<string, mixed> $variables Template variables
     * @return array<string, mixed> API payload or error
     */
    public static function generateApiPayload(string $phoneNumber, string $templateName, array $variables): array
    {
        $template = self::getTemplate($templateName);

        if (!$template) {
            return [
                'success' => false,
                'error' => "Template '$templateName' not found"
            ];
        }

        // Validate variables
        $validation = self::validateVariables($templateName, $variables);
        if (!$validation['valid']) {
            return [
                'success' => false,
                'error' => $validation['error']
            ];
        }

        try {
            // Build template components
            $components = [];

            // Header component (if exists)
            if (isset($template['header'])) {
                $headerComponent = [
                    'type' => 'header'
                ];

                if ($template['header']['type'] === 'TEXT') {
                    $headerComponent['parameters'] = [];
                }

                $components[] = $headerComponent;
            }

            // Body component with parameters
            $bodyParameters = [];
            foreach ($template['variables'] as $variableName) {
                $bodyParameters[] = [
                    'type' => 'text',
                    'text' => (string) ($variables[$variableName] ?? '')
                ];
            }

            $components[] = [
                'type' => 'body',
                'parameters' => $bodyParameters
            ];

            // Button components (if exists)
            if (isset($template['buttons'])) {
                $buttonParameters = [];
                $buttonIndex = 0;

                foreach ($template['buttons'] as $button) {
                    if ($button['type'] === 'URL') {
                        // For URL buttons, we need to provide the dynamic part of the URL
                        $buttonParameters[] = [
                            'type' => 'text',
                            'text' => (string) ($variables[$template['variables'][array_search('{{3}}', [$button['url']])] ?? $variables['claim_url'] ?? $variables['replay_url'] ?? $variables['website_url'] ?? '')
                        ];
                    }
                    $buttonIndex++;
                }

                if (!empty($buttonParameters)) {
                    $components[] = [
                        'type' => 'button',
                        'sub_type' => 'url',
                        'index' => '0',
                        'parameters' => $buttonParameters
                    ];
                }
            }

            $payload = [
                'messaging_product' => 'whatsapp',
                'to' => $phoneNumber,
                'type' => 'template',
                'template' => [
                    'name' => $template['name'],
                    'language' => [
                        'code' => $template['language']
                    ],
                    'components' => $components
                ]
            ];

            return [
                'success' => true,
                'payload' => $payload
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Payload generation failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Get template categories for organization
     *
     * @return array<string, array>
     */
    public static function getTemplatesByCategory(): array
    {
        $categorized = [
            'TRANSACTIONAL' => [],
            'MARKETING' => []
        ];

        foreach (self::$templates as $name => $template) {
            $category = $template['category'] ?? 'MARKETING';
            $categorized[$category][$name] = $template;
        }

        return $categorized;
    }

    /**
     * Validate template format for Meta Business API
     *
     * @param string $templateName Template name to validate
     * @return array<string, mixed> Validation result
     */
    public static function validateTemplateFormat(string $templateName): array
    {
        $template = self::getTemplate($templateName);

        if (!$template) {
            return [
                'valid' => false,
                'errors' => ["Template '$templateName' not found"]
            ];
        }

        $errors = [];

        // Check required fields
        $requiredFields = ['name', 'category', 'language', 'body'];
        foreach ($requiredFields as $field) {
            if (!isset($template[$field])) {
                $errors[] = "Missing required field: $field";
            }
        }

        // Validate category
        $validCategories = ['TRANSACTIONAL', 'MARKETING', 'AUTHENTICATION'];
        if (isset($template['category']) && !in_array($template['category'], $validCategories)) {
            $errors[] = "Invalid category: {$template['category']}";
        }

        // Validate language code
        if (isset($template['language']) && !preg_match('/^[a-z]{2}_[A-Z]{2}$/', $template['language'])) {
            $errors[] = "Invalid language code format: {$template['language']}";
        }

        // Validate body text length (WhatsApp limit is 1024 characters)
        if (isset($template['body']['text']) && strlen($template['body']['text']) > 1024) {
            $errors[] = "Body text exceeds 1024 character limit";
        }

        // Validate header text length (if exists)
        if (isset($template['header']['text']) && strlen($template['header']['text']) > 60) {
            $errors[] = "Header text exceeds 60 character limit";
        }

        // Validate footer text length (if exists)
        if (isset($template['footer']['text']) && strlen($template['footer']['text']) > 60) {
            $errors[] = "Footer text exceeds 60 character limit";
        }

        // Validate variable placeholders
        if (isset($template['variables']) && isset($template['body']['text'])) {
            $bodyText = $template['body']['text'];
            $variableCount = count($template['variables']);

            for ($i = 1; $i <= $variableCount; $i++) {
                if (strpos($bodyText, "{{$i}}") === false) {
                    $errors[] = "Missing variable placeholder {{$i}} in body text";
                }
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }

    /**
     * Export templates for Meta Business API submission
     *
     * @return array<string, mixed> Export data
     */
    public static function exportForMetaAPI(): array
    {
        $export = [];

        foreach (self::$templates as $templateName => $template) {
            $export[$templateName] = [
                'name' => $template['name'],
                'category' => $template['category'],
                'language' => $template['language'],
                'components' => []
            ];

            // Add header component
            if (isset($template['header'])) {
                $export[$templateName]['components'][] = [
                    'type' => 'HEADER',
                    'format' => $template['header']['type'],
                    'text' => $template['header']['text']
                ];
            }

            // Add body component
            $export[$templateName]['components'][] = [
                'type' => 'BODY',
                'text' => $template['body']['text']
            ];

            // Add footer component
            if (isset($template['footer'])) {
                $export[$templateName]['components'][] = [
                    'type' => 'FOOTER',
                    'text' => $template['footer']['text']
                ];
            }

            // Add button components
            if (isset($template['buttons'])) {
                foreach ($template['buttons'] as $button) {
                    $export[$templateName]['components'][] = [
                        'type' => 'BUTTONS',
                        'buttons' => [
                            [
                                'type' => $button['type'],
                                'text' => $button['text'],
                                'url' => $button['url'] ?? null
                            ]
                        ]
                    ];
                }
            }
        }

        return $export;
    }

    /**
     * Preview template as formatted text (for testing)
     *
     * @param string $templateName Template name
     * @param array<string, mixed> $variables Template variables
     * @return string Formatted preview text
     */
    public static function previewAsText(string $templateName, array $variables): string
    {
        $rendered = self::renderTemplate($templateName, $variables);

        if (!$rendered['success']) {
            return "Error: {$rendered['error']}";
        }

        $preview = "";

        if (!empty($rendered['rendered']['header'])) {
            $preview .= "📱 " . $rendered['rendered']['header'] . "\n\n";
        }

        $preview .= $rendered['rendered']['body'];

        if (!empty($rendered['rendered']['footer'])) {
            $preview .= "\n\n" . $rendered['rendered']['footer'];
        }

        return $preview;
    }
}
