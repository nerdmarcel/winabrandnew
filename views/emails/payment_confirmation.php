<?php
/**
 * File: views/emails/payment_confirmation.php
 * Location: views/emails/payment_confirmation.php
 *
 * WinABN Payment Confirmation Email Template
 *
 * Email template sent to participants after successful payment confirmation.
 * Variables available: first_name, last_name, game_name, prize_name, payment_amount,
 * payment_currency, payment_reference, continue_url, round_status
 *
 * @package WinABN\Views\Emails
 * @author WinABN Development Team
 * @version 1.0
 */

// This file is included by EmailSender and parsed for @subject, @html, and @text sections
?>

@subject: ✅ Payment Confirmed - Continue Your {{game_name}} Competition!

@html:
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Confirmed</title>
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
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            color: white;
            padding: 40px 30px;
            text-align: center;
        }
        .header h1 {
            margin: 0;
            font-size: 28px;
            font-weight: 700;
        }
        .header .checkmark {
            font-size: 64px;
            margin-bottom: 20px;
            display: block;
        }
        .content {
            padding: 40px 30px;
        }
        .status-banner {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            color: white;
            padding: 20px;
            border-radius: 10px;
            text-align: center;
            margin-bottom: 30px;
        }
        .status-banner h2 {
            margin: 0;
            font-size: 20px;
            font-weight: 600;
        }
        .payment-details {
            background-color: #f8f9fa;
            border: 1px solid #e9ecef;
            border-radius: 10px;
            padding: 25px;
            margin: 25px 0;
        }
        .payment-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 12px;
            padding-bottom: 8px;
            border-bottom: 1px solid #e9ecef;
        }
        .payment-row:last-child {
            margin-bottom: 0;
            border-bottom: none;
            font-weight: 600;
            font-size: 16px;
        }
        .payment-label {
            color: #6c757d;
        }
        .payment-value {
            font-weight: 600;
            text-align: right;
        }
        .continue-button {
            display: inline-block;
            background: linear-gradient(135deg, #007cba 0%, #0056b3 100%);
            color: white;
            text-decoration: none;
            padding: 18px 40px;
            border-radius: 50px;
            font-size: 18px;
            font-weight: 600;
            text-align: center;
            margin: 30px 0;
            box-shadow: 0 4px 15px rgba(0, 124, 186, 0.3);
            transition: all 0.3s ease;
        }
        .continue-button:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(0, 124, 186, 0.4);
        }
        .game-info {
            background: linear-gradient(135deg, #ff6b35 0%, #f7931e 100%);
            color: white;
            padding: 20px;
            border-radius: 10px;
            margin: 25px 0;
        }
        .game-info h3 {
            margin: 0 0 10px 0;
            font-size: 18px;
        }
        .prize-highlight {
            font-size: 20px;
            font-weight: 700;
            margin-top: 10px;
        }
        .instructions {
            background-color: #e7f3ff;
            border: 1px solid #b3d9ff;
            border-radius: 8px;
            padding: 20px;
            margin: 25px 0;
        }
        .instructions h3 {
            margin: 0 0 15px 0;
            color: #0056b3;
            font-size: 18px;
        }
        .instructions ol {
            margin: 0;
            padding-left: 20px;
            color: #0056b3;
        }
        .instructions li {
            margin-bottom: 8px;
        }
        .round-status {
            background-color: #fff3cd;
            border: 1px solid #ffeaa7;
            border-radius: 8px;
            padding: 15px;
            margin: 25px 0;
            text-align: center;
        }
        .round-status .participants-count {
            font-size: 24px;
            font-weight: 700;
            color: #856404;
            margin-bottom: 5px;
        }
        .tips-section {
            background-color: #f8f9fa;
            border-left: 4px solid #17a2b8;
            padding: 20px;
            margin: 25px 0;
        }
        .tips-section h3 {
            margin: 0 0 15px 0;
            color: #17a2b8;
            font-size: 18px;
        }
        .tips-section ul {
            margin: 0;
            padding-left: 20px;
        }
        .tips-section li {
            margin-bottom: 8px;
            color: #495057;
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
        .receipt-info {
            font-size: 12px;
            color: #adb5bd;
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid #e9ecef;
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
            .header .checkmark {
                font-size: 48px;
            }
            .continue-button {
                display: block;
                margin: 25px 0;
            }
            .payment-row {
                flex-direction: column;
                gap: 5px;
            }
            .payment-value {
                text-align: left;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <span class="checkmark">✅</span>
            <h1>Payment Confirmed!</h1>
        </div>

        <div class="content">
            <div class="status-banner">
                <h2>Thank you {{first_name}}! Your payment has been successfully processed.</h2>
            </div>

            <p style="font-size: 18px; margin-bottom: 25px;">
                Great news! Your payment has been confirmed and you're now ready to continue
                with the remaining questions in {{game_name}}.
            </p>

            <div class="payment-details">
                <h3 style="margin: 0 0 20px 0; color: #007cba;">💳 Payment Summary</h3>
                <div class="payment-row">
                    <span class="payment-label">Competition Entry:</span>
                    <span class="payment-value">{{game_name}}</span>
                </div>
                <div class="payment-row">
                    <span class="payment-label">Amount Paid:</span>
                    <span class="payment-value">{{payment_currency}} {{payment_amount}}</span>
                </div>
                <div class="payment-row">
                    <span class="payment-label">Payment Method:</span>
                    <span class="payment-value">{{payment_method}}</span>
                </div>
                <div class="payment-row">
                    <span class="payment-label">Transaction ID:</span>
                    <span class="payment-value">{{payment_reference}}</span>
                </div>
                <div class="payment-row">
                    <span class="payment-label">Payment Date:</span>
                    <span class="payment-value">{{current_datetime}}</span>
                </div>
            </div>

            <div class="game-info">
                <h3>🎯 Competition Details</h3>
                <p style="margin: 0;">You're competing for: <span class="prize-highlight">{{prize_name}}</span></p>
                <p style="margin: 5px 0 0 0; opacity: 0.9;">Questions completed: 3 of 9 • Remaining: 6 questions</p>
            </div>

            <div style="text-align: center;">
                <a href="{{continue_url}}" class="continue-button">
                    🚀 Continue Competition
                </a>
            </div>

            <div class="round-status">
                <div class="participants-count">{{round_participants}} / {{max_participants}}</div>
                <p style="margin: 0; color: #856404;">
                    <strong>Participants in this round</strong><br>
                    The competition ends when we reach {{max_participants}} paid participants
                </p>
            </div>

            <div class="instructions">
                <h3>📋 What Happens Next:</h3>
                <ol>
                    <li><strong>Click "Continue Competition"</strong> to access questions 4-9</li>
                    <li><strong>Answer each question</strong> as quickly and accurately as possible</li>
                    <li><strong>Complete all 9 questions</strong> to be eligible to win</li>
                    <li><strong>Fastest correct completion wins</strong> when the round fills up</li>
                    <li><strong>Winner announcement</strong> via email and WhatsApp (if opted in)</li>
                </ol>
            </div>

            <div class="tips-section">
                <h3>💡 Pro Tips for Success:</h3>
                <ul>
                    <li><strong>Stay focused:</strong> You have 10 seconds per question</li>
                    <li><strong>Read carefully:</strong> Make sure you understand each question</li>
                    <li><strong>Trust your instincts:</strong> Your first answer is often correct</li>
                    <li><strong>Stay calm:</strong> Rushing can lead to mistakes</li>
                    <li><strong>Good luck!</strong> You've got this! 🍀</li>
                </ul>
            </div>

            <p style="margin-top: 30px;">
                If you experience any technical issues or have questions about the competition,
                please contact our support team at
                <a href="mailto:{{support_email}}" style="color: #007cba;">{{support_email}}</a>
            </p>

            <div class="receipt-info">
                <p><strong>Receipt Information:</strong></p>
                <p>
                    This email serves as your payment receipt for {{game_name}} competition entry.
                    Keep this email for your records. Payment processed by {{app_name}} on {{current_date}}.
                </p>
                <p>Customer: {{first_name}} {{last_name}} ({{email}})</p>
            </div>
        </div>

        <div class="footer">
            <p><strong>{{app_name}}</strong></p>
            <p>The UK's most exciting competition platform</p>

            <p style="margin-top: 20px; font-size: 12px; color: #adb5bd;">
                This email was sent to {{first_name}} {{last_name}} because you made a payment for our competition.<br>
                Questions? Contact us at {{support_email}}<br>
                © {{current_year}} {{app_name}}. All rights reserved.
            </p>
        </div>
    </div>
</body>
</html>

@text:
✅ PAYMENT CONFIRMED!

Thank you {{first_name}}! Your payment has been successfully processed.

PAYMENT SUMMARY:
- Competition: {{game_name}}
- Amount Paid: {{payment_currency}} {{payment_amount}}
- Payment Method: {{payment_method}}
- Transaction ID: {{payment_reference}}
- Payment Date: {{current_datetime}}

COMPETITION DETAILS:
🎯 You're competing for: {{prize_name}}
📊 Questions completed: 3 of 9 (6 remaining)
👥 Round status: {{round_participants}} / {{max_participants}} participants

CONTINUE COMPETITION:
{{continue_url}}

WHAT HAPPENS NEXT:
1. Click the link above to access questions 4-9
2. Answer each question as quickly and accurately as possible
3. Complete all 9 questions to be eligible to win
4. Fastest correct completion wins when the round fills up
5. Winner announcement via email and WhatsApp

PRO TIPS:
• Stay focused - you have 10 seconds per question
• Read carefully and trust your instincts
• Stay calm - rushing can lead to mistakes
• Good luck! 🍀

SUPPORT:
Questions? Contact us at {{support_email}}

RECEIPT:
This email serves as your payment receipt for {{game_name}} competition entry.
Customer: {{first_name}} {{last_name}} ({{email}})
Payment processed by {{app_name}} on {{current_date}}.

{{app_name}} - The UK's most exciting competition platform
{{app_url}}

© {{current_year}} {{app_name}}. All rights reserved.
