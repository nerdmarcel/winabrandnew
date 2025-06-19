<?php
/**
 * File: views/emails/winner_notification.php
 * Location: views/emails/winner_notification.php
 *
 * WinABN Winner Notification Email Template
 *
 * Email template sent to competition winners with prize claim instructions.
 * Variables available: first_name, last_name, prize_name, prize_value, claim_url, game_name
 *
 * @package WinABN\Views\Emails
 * @author WinABN Development Team
 * @version 1.0
 */

// This file is included by EmailSender and parsed for @subject, @html, and @text sections
?>

@subject: 🎉 Congratulations {{first_name}}! You've Won {{prize_name}}!

@html:
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>You're a Winner!</title>
    <style>
        body {
            margin: 0;
            padding: 0;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            line-height: 1.6;
            color: #333333;
            background-color: #f8f9fa;
        }
        .container {
            max-width: 600px;
            margin: 0 auto;
            background-color: #ffffff;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }
        .header {
            background: linear-gradient(135deg, #007cba 0%, #005a8b 100%);
            color: white;
            padding: 40px 30px;
            text-align: center;
        }
        .header h1 {
            margin: 0;
            font-size: 32px;
            font-weight: 700;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
        }
        .header .trophy {
            font-size: 64px;
            margin-bottom: 20px;
            display: block;
        }
        .content {
            padding: 40px 30px;
        }
        .winner-announcement {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            color: white;
            padding: 25px;
            border-radius: 10px;
            text-align: center;
            margin-bottom: 30px;
        }
        .winner-announcement h2 {
            margin: 0 0 10px 0;
            font-size: 24px;
            font-weight: 600;
        }
        .prize-details {
            background-color: #f8f9fa;
            border: 2px solid #e9ecef;
            border-radius: 10px;
            padding: 25px;
            margin: 30px 0;
            text-align: center;
        }
        .prize-name {
            font-size: 22px;
            font-weight: 700;
            color: #007cba;
            margin-bottom: 10px;
        }
        .prize-value {
            font-size: 28px;
            font-weight: 800;
            color: #28a745;
            margin-bottom: 15px;
        }
        .claim-button {
            display: inline-block;
            background: linear-gradient(135deg, #ff6b35 0%, #f7931e 100%);
            color: white;
            text-decoration: none;
            padding: 18px 40px;
            border-radius: 50px;
            font-size: 18px;
            font-weight: 600;
            text-align: center;
            margin: 30px 0;
            box-shadow: 0 4px 15px rgba(255, 107, 53, 0.3);
            transition: all 0.3s ease;
        }
        .claim-button:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(255, 107, 53, 0.4);
        }
        .instructions {
            background-color: #fff3cd;
            border: 1px solid #ffeaa7;
            border-radius: 8px;
            padding: 20px;
            margin: 25px 0;
        }
        .instructions h3 {
            margin: 0 0 15px 0;
            color: #856404;
            font-size: 18px;
        }
        .instructions ol {
            margin: 0;
            padding-left: 20px;
            color: #856404;
        }
        .instructions li {
            margin-bottom: 8px;
        }
        .important-note {
            background-color: #ffeaa7;
            border-left: 4px solid #f39c12;
            padding: 15px;
            margin: 25px 0;
        }
        .important-note strong {
            color: #e67e22;
        }
        .game-info {
            border-top: 1px solid #e9ecef;
            padding-top: 20px;
            margin-top: 30px;
            font-size: 14px;
            color: #6c757d;
        }
        .footer {
            background-color: #f8f9fa;
            padding: 30px;
            text-align: center;
            border-top: 1px solid #e9ecef;
        }
        .footer p {
            margin: 0;
            color: #6c757d;
            font-size: 14px;
        }
        .social-links {
            margin: 20px 0;
        }
        .social-links a {
            display: inline-block;
            margin: 0 10px;
            color: #007cba;
            text-decoration: none;
        }
        @media (max-width: 600px) {
            .container {
                margin: 0;
                border-radius: 0;
            }
            .header, .content, .footer {
                padding: 25px 20px;
            }
            .header h1 {
                font-size: 28px;
            }
            .header .trophy {
                font-size: 48px;
            }
            .claim-button {
                display: block;
                margin: 25px 0;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <span class="trophy">🏆</span>
            <h1>WINNER!</h1>
        </div>

        <div class="content">
            <div class="winner-announcement">
                <h2>Congratulations {{first_name}} {{last_name}}!</h2>
                <p>You are the fastest correct finisher of {{game_name}}!</p>
            </div>

            <p style="font-size: 18px; margin-bottom: 25px;">
                We're thrilled to announce that you've won our latest competition!
                Your quick thinking and lightning-fast responses have earned you an amazing prize.
            </p>

            <div class="prize-details">
                <div class="prize-name">{{prize_name}}</div>
                <div class="prize-value">Worth {{prize_value}}</div>
                <p style="margin: 0; color: #6c757d;">Your incredible prize is waiting for you!</p>
            </div>

            <div style="text-align: center;">
                <a href="{{claim_url}}" class="claim-button">
                    🎁 Claim Your Prize Now
                </a>
            </div>

            <div class="instructions">
                <h3>📋 Next Steps:</h3>
                <ol>
                    <li><strong>Click the "Claim Your Prize" button above</strong> to access your secure prize claim page</li>
                    <li><strong>Verify your identity</strong> using the details you provided during the competition</li>
                    <li><strong>Enter your shipping address</strong> where you'd like your prize delivered</li>
                    <li><strong>Confirm your claim</strong> and we'll process your prize for shipment</li>
                    <li><strong>Track your delivery</strong> - we'll send you tracking information once shipped</li>
                </ol>
            </div>

            <div class="important-note">
                <p><strong>⚠️ Important:</strong> You have <strong>30 days</strong> from today to claim your prize.
                After this period, the prize will be forfeited and offered to another participant.</p>
            </div>

            <p style="margin-top: 30px;">
                If you have any questions about claiming your prize or need assistance,
                please don't hesitate to contact our support team at
                <a href="mailto:{{support_email}}" style="color: #007cba;">{{support_email}}</a>
            </p>

            <div class="game-info">
                <p><strong>Competition:</strong> {{game_name}}</p>
                <p><strong>Winner Selected:</strong> {{current_datetime}}</p>
                <p><strong>Prize Claim Deadline:</strong> {{#if claim_deadline}}{{claim_deadline}}{{/if}}</p>
            </div>
        </div>

        <div class="footer">
            <p><strong>{{app_name}}</strong></p>
            <p>The UK's most exciting competition platform</p>

            <div class="social-links">
                <a href="{{app_url}}">🌐 Visit Website</a>
                <a href="mailto:{{support_email}}">📧 Contact Support</a>
            </div>

            <p style="margin-top: 20px; font-size: 12px; color: #adb5bd;">
                This email was sent to {{first_name}} {{last_name}} ({{email}}) because you won our competition.<br>
                © {{current_year}} {{app_name}}. All rights reserved.
            </p>
        </div>
    </div>
</body>
</html>

@text:
🏆 WINNER! 🏆

Congratulations {{first_name}} {{last_name}}!

You are the fastest correct finisher of {{game_name}} and have won:

{{prize_name}}
Worth {{prize_value}}

CLAIM YOUR PRIZE:
{{claim_url}}

NEXT STEPS:
1. Click the link above to access your secure prize claim page
2. Verify your identity using the details you provided
3. Enter your shipping address for delivery
4. Confirm your claim and we'll process shipment
5. Track your delivery with the information we'll send you

⚠️ IMPORTANT: You have 30 days from today to claim your prize. After this period, the prize will be forfeited.

COMPETITION DETAILS:
- Game: {{game_name}}
- Winner Selected: {{current_datetime}}
- Prize Claim Deadline: {{#if claim_deadline}}{{claim_deadline}}{{/if}}

Questions? Contact us at {{support_email}}

{{app_name}} - The UK's most exciting competition platform
{{app_url}}

This email was sent to {{first_name}} {{last_name}} because you won our competition.
© {{current_year}} {{app_name}}. All rights reserved.
