#!/usr/bin/env node

import { createRequire } from 'module';
import { readFileSync } from 'fs';

const require = createRequire(import.meta.url);
const webPush = require('web-push');

function readPayload() {
    try {
        const raw = readFileSync(0, 'utf8');
        return raw.trim() === '' ? {} : JSON.parse(raw);
    } catch (error) {
        return {};
    }
}

function respond(payload) {
    process.stdout.write(JSON.stringify(payload));
}

async function handleSend(input) {
    const vapid = input.vapid || {};
    const payload = input.payload || {};
    const options = input.options || {};
    const subscriptions = Array.isArray(input.subscriptions) ? input.subscriptions : [];

    webPush.setVapidDetails(
        vapid.subject || 'mailto:no-reply@localhost.local',
        vapid.publicKey || '',
        vapid.privateKey || ''
    );

    const body = JSON.stringify(payload);
    const reports = [];

    for (const subscription of subscriptions) {
        const endpoint = subscription && subscription.endpoint ? subscription.endpoint : '';

        try {
            await webPush.sendNotification(subscription, body, options);
            reports.push({
                success: true,
                endpoint: endpoint,
            });
        } catch (error) {
            const statusCode = error && typeof error.statusCode === 'number' ? error.statusCode : 0;
            reports.push({
                success: false,
                endpoint: endpoint,
                expired: statusCode === 404 || statusCode === 410,
                reason: (error && (error.body || error.message)) || 'Unknown error',
            });
        }
    }

    respond({
        success: reports.some(function (report) {
            return !!report.success;
        }),
        reports: reports,
    });
}

async function main() {
    const command = process.argv[2] || '';
    const input = readPayload();

    switch (command) {
        case 'check':
            respond({
                success: true,
                package: 'web-push',
            });
            break;

        case 'generate-keys': {
            const keys = webPush.generateVAPIDKeys();
            respond({
                success: true,
                publicKey: keys.publicKey,
                privateKey: keys.privateKey,
            });
            break;
        }

        case 'send':
            await handleSend(input);
            break;

        default:
            respond({
                success: false,
                message: 'Unknown web push command.',
            });
            process.exit(1);
    }
}

main().catch(function (error) {
    const message = error && error.message ? error.message : String(error);
    process.stderr.write(message);
    process.exit(1);
});
