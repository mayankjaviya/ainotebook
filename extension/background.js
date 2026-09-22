chrome.runtime.onInstalled.addListener(function () {
    chrome.sidePanel.setPanelBehavior({ openPanelOnActionClick: true })
        .catch(function (e) { console.error('Notebook: ' + e.message); });
});

chrome.commands.onCommand.addListener(function (command) {
    if (command !== 'open-panel') { return; }

    chrome.windows.getCurrent(function (win) {
        chrome.sidePanel.open({ windowId: win.id });
    });
});

var REMINDER_URL = 'http://localhost/tools/notebook/reminders.php';

chrome.runtime.onInstalled.addListener(function () {
    chrome.alarms.create('mya-reminders', { periodInMinutes: 1 });
});

chrome.runtime.onStartup.addListener(function () {
    chrome.alarms.create('mya-reminders', { periodInMinutes: 1 });
});

chrome.alarms.onAlarm.addListener(function (alarm) {
    if (alarm.name !== 'mya-reminders') { return; }

    fetch(REMINDER_URL, { cache: 'no-store' })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            (data.reminders || []).forEach(function (reminder) {
                chrome.notifications.create('mya-' + reminder.id, {
                    type:    'basic',
                    iconUrl: 'icon.png',
                    title:   'Reminder',
                    message: reminder.text,
                    priority: 2,
                    requireInteraction: true
                });
            });
        })
        .catch(function () { /* app or Apache down: try again next minute */ });
});
