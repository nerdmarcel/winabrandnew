<?php
/**
 * File: views/emails/prize_claim_reminder.php
 * Location: views/emails/prize_claim_reminder.php
 *
 * WinABN Prize Claim Reminder Email Template
 *
 * Email template sent to winners who haven't claimed their prize after 7 days.
 * Variables available: first_name, last_name, prize_name, prize_value, claim_url,
 * days_remaining, game_name, won_date, expiry_date
 *
 * @package WinABN\Views\Emails
 * @author WinABN Development Team
 * @version 1.0
 */

// This file is included by EmailSender and parsed for @subject, @html, and @text sections
?>

@subject: ⏰ Reminder: Claim Your {{prize_name}} - {{days_remaining}} Days Left!

@html:
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Prize Claim Reminder</title>
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
            background: linear-gradient(135deg, #ff6b35 0%, #f7931e 100%);
            color: white;
            padding: 40px 30px;
            text-align: center;
        }
        .header h1 {
            margin: 0;
            font-size: 28px;
            font-weight: 700;
        }
        .header .clock {
            font-size: 64px;
            margin-bottom: 20px;
            display: block;
        }
        .content {
            padding: 40px 30px;
        }
        .urgency-banner {
            background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
            color: white;
            padding: 20px;
            border-radius: 10px;
            text-align: center;
            margin-bottom: 30px;
            position: relative;
            overflow: hidden;
        }
        .urgency-banner::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
            animation: shine 2s infinite;
        }
        @keyframes shine {
            0% { left: -100%; }
            100% { left: 100%; }
        }
        .urgency-banner h2 {
            margin: 0;
            font-size: 22px;
            font-weight: 700;
        }
        .countdown {
            font-size: 36px;
            font-weight: 800;
            margin: 10px 0;
            text-shadow: 0 2px 4px rgba(0,0,0,0.3);
        }
        .prize-reminder {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            color: white;
            padding: 25px;
            border-radius: 10px;
            text-align: center;
            margin: 25px 0;
        }
        .prize-name {
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 10px;
        }
        .prize-value {
            font-size: 32px;
            font-weight: 800;
            margin-bottom: 15px;
            text-shadow: 0 2px 4px rgba(0,0,0,0.2);
        }
        .claim-button {
            display: inline-block;
            background: linear-gradient(135deg, #007cba 0%, #0056b3 100%);
            color: white;
            text-decoration: none;
            padding: 20px 50px;
            border-radius: 50px;
            font-size: 20px;
            font-weight: 700;
            text-align: center;
            margin: 30px 0;
            box-shadow: 0 6px 20px rgba(0, 124, 186, 0.4);
            transition: all 0.3s ease;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        .claim-button:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(0, 124, 186, 0.5);
        }
        .timeline {
            background-color: #f8f9fa;
            border-left: 4px solid #007cba;
            padding: 20px;
            margin: 25px 0;
        }
        .timeline h3 {
            margin: 0 0 15px 0;
            color: #007cba;
            font-size: 18px;
        }
        .timeline-item {
            margin-bottom: 15px;
            padding-left: 25px;
            position: relative;
        }
        .timeline-item::before {
            content: '📅';
            position: absolute;
            left: 0;
            top: 0;
        }
        .timeline-item.completed {
            color: #28a745;
            text-decoration: line-through;
        }
        .timeline-item.current {
            color: #dc3545;
            font-weight: 600;
        }
        .warning-box {
            background-color: #fff3cd;
            border: 2px solid #ffc107;
            border-radius: 10px;
            padding: 20px;
            margin: 25px 0;
            text-align: center;
        }
        .warning-box h3 {
            margin: 0 0 15px 0;
            color: #856404;
            font-size: 20px;
        }
        .warning-box p {
            margin: 0;
            color: #856404;
            font-size: 16px;
            font-weight: 500;
        }
        .contact-support {
            background-color: #e7f3ff;
            border: 1px solid #b3d9ff;
            border-radius: 8px;
            padding: 20px;
            margin: 25px 0;
            text-align: center;
        }
        .contact-support h3 {
            margin: 0 0 15px 0;
            color: #0056b3;
        }
        .support-button {
            display: inline-block;
            background: #17a2b8;
            color: white;
            text-decoration: none;
            padding: 12px 30px;
            border-radius: 25px;
            font-weight: 600;
            margin-top: 10px;
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
        .winner-badge {
            background: linear-gradient(135deg, #ffd700 0%, #ffed4e 100%);
            color: #856404;
            padding: 15px;
            border-radius: 50px;
            text-align: center;
            margin: 25px 0;
            font-weight: 700;
            font-size: 18px;
            border: 3px solid #ffc107;
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
                font-size: 24px;
            }
            .header .clock {
                font-size: 48px;
            }
            .claim-button {
                display: block;
                margin: 25px 0;
                padding: 18px 30px;
                font-size: 18px;
            }
            .countdown {
                font-size: 28px;
            }
            .prize-value {
                font-size: 24px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <span class="clock">⏰</span>
            <h1>Prize Claim Reminder</h1>
        </div>

        <div class="content">
            <div class="winner-badge">
                🏆 {{first_name}} {{last_name}} - COMPETITION WINNER 🏆
            </div>

            <div class="urgency-banner">
                <h2>Don't Miss Out!</h2>
                <div class="countdown">{{days_remaining}} Days Left</div>
                <p style="margin: 0; font-size: 16px;">to claim your amazing prize</p>
            </div>

            <p style="font-size: 18px; margin-bottom: 25px;">
                Hi {{first_name}}, this is a friendly reminder that you won our {{game_name}} competition
                but haven't claimed your prize yet. Your prize claim period expires soon!
            </p>

            <div class="prize-reminder">
                <div class="prize-name">{{prize_name}}</div>
                <div class="prize-value">Worth {{prize_value}}</div>
                <p style="margin: 0; opacity: 0.9;">This incredible prize is still waiting for you!</p>
            </div>

            <div style="text-align: center;">
                <a href="{{claim_url}}" class="claim-button">
                    🎁 Claim Prize Now
                </a>
            </div>

            <div class="timeline">
                <h3>📋 Prize Claim Timeline:</h3>
                <div class="timeline-item completed">✅ Won competition on {{won_date}}</div>
                <div class="timeline-item completed">✅ Winner notification sent</div>
                <div class="timeline-item current">⚠️ Prize claim period (expires {{expiry_date}})</div>
                <div class="timeline-item">📦 Prize processing & shipping</div>
                <div class="timeline-item">🚚 Delivery to your address</div>
            </div>

            <div class="warning-box">
                <h3>⚠️ Important Deadline Notice</h3>
                <p>
                    Your prize claim period expires on <strong>{{expiry_date}}</strong>.
                    If you don't claim your prize by this date, it will be forfeited and
                    cannot be recovered. Don't let this amazing prize slip away!
                </p>
            </div>

            <div style="background-color: #f8f9fa; padding: 20px; border-radius: 8px; margin: 25px 0;">
                <h3 style="margin: 0 0 15px 0; color: #007cba;">🚀 Quick Claim Process:</h3>
                <ol style="margin: 0; padding-left: 20px;">
                    <li><strong>Click the "Claim Prize Now" button above</strong></li>
                    <li><strong>Verify your identity</strong> with the details you provided</li>
                    <li><strong>Enter your shipping address</strong> for prize delivery</li>
                    <li><strong>Confirm your claim</strong> - that's it!</li>
                </ol>
                <p style="margin: 15px 0 0 0; color: #6c757d; font-style: italic;">
                    The entire process takes less than 2 minutes!
                </p>
            </div>

            <div class="contact-support">
                <h3>Need Help? 🤝</h3>
                <p>
                    If you're having trouble claiming your prize or have any questions,
                    our support team is here to help you every step of the way.
                </p>
                <a href="mailto:{{support_email}}" class="support-button">
                    📧 Contact Support
                </a>
            </div>

            <p style="margin-top: 30px; font-weight: 500;">
                We're excited to get your prize to you! Don't wait - claim it today to ensure
                you don't miss out on this fantastic reward for your quick thinking and skills.
            </p>

            <div style="background-color: #e8f5e8; border: 1px solid #c3e6c3; border-radius: 8px; padding: 15px; margin: 25px 0;">
                <p style="margin: 0; color: #155724; text-align: center; font-weight: 600;">
                    🎉 Congratulations again on your victory in {{game_name}}! 🎉
                </p>
            </div>
        </div>

        <div class="footer">
            <p><strong>{{app_name}}</strong></p>
            <p>The UK's most exciting competition platform</p>

            <p style="margin-top: 20px; font-size: 12px; color: #adb5bd;">
                This reminder was sent to {{first_name}} {{last_name}} because you won our competition.<br>
                Prize claim deadline: {{expiry_date}}<br>
                Questions? Contact us at {{support_email}}<br>
                © {{current_year}} {{app_name}}. All rights reserved.
            </p>
        </div>
    </div>
</body>
</html>

@text:
⏰ PRIZE CLAIM REMINDER ⏰

🏆 {{first_name}} {{last_name}} - COMPETITION WINNER 🏆

DON'T MISS OUT!
{{days_remaining}} Days Left to claim your amazing prize

Hi {{first_name}}, this is a friendly reminder that you won our {{game_name}} competition but haven't claimed your prize yet. Your prize claim period expires soon!

YOUR PRIZE:
{{prize_name}}
Worth {{prize_value}}

CLAIM YOUR PRIZE:
{{claim_url}}

TIMELINE:
✅ Won competition on {{won_date}}
✅ Winner notification sent
⚠️ Prize claim period (expires {{expiry_date}})
📦 Prize processing & shipping
🚚 Delivery to your address

⚠️ IMPORTANT DEADLINE NOTICE:
Your prize claim period expires on {{expiry_date}}. If you don't claim your prize by this date, it will be forfeited and cannot be recovered. Don't let this amazing prize slip away!

QUICK CLAIM PROCESS:
1. Click the claim link above
2. Verify your identity with the details you provided
3. Enter your shipping address for prize delivery
4. Confirm your claim - that's it!

The entire process takes less than 2 minutes!

NEED HELP?
If you're having trouble claiming your prize or have any questions, contact us at {{support_email}}

We're excited to get your prize to you! Don't wait - claim it today to ensure you don't miss out on this fantastic reward.

🎉 Congratulations again on your victory in {{game_name}}! 🎉

{{app_name}} - The UK's most exciting competition platform
{{app_url}}

Prize claim deadline: {{expiry_date}}
Questions? Contact us at {{support_email}}
© {{current_year}} {{app_name}}. All rights reserved.
