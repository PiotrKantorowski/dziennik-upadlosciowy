"use strict";

const assert = require("assert");
const fs = require("fs");
const path = require("path");
const vm = require("vm");

const extensionDir = path.resolve(__dirname, "..", "chrome_extension");

function eventStub() {
  return { addListener() {}, removeListener() {} };
}

async function testBackgroundRegistersJobBeforeNavigation() {
  const source = fs.readFileSync(path.join(extensionDir, "background.js"), "utf8");
  const events = [];
  const tabs = new Map();
  const storage = {};
  const browserEvent = eventStub();
  let context;

  const chrome = {
    runtime: {
      getURL: (file) => `chrome-extension://duir-runtime-test/${file}`,
      getManifest: () => ({ version: "runtime-test" }),
      getPlatformInfo: async () => ({}),
      onInstalled: browserEvent,
      onStartup: browserEvent,
      onMessage: browserEvent,
    },
    storage: {
      local: {
        get: async (key) => typeof key === "string" ? { [key]: storage[key] } : { ...storage },
        set: async (values) => Object.assign(storage, values),
      },
    },
    tabs: {
      create: async (details) => {
        events.push({ step: "create", url: details.url });
        const tab = { id: 42, windowId: 7, url: details.url };
        tabs.set(tab.id, tab);
        return { ...tab };
      },
      update: async (tabId, details) => {
        const job = vm.runInContext(`jobsByTab[${Number(tabId)}]`, context);
        events.push({
          step: "update",
          url: details.url,
          jobPresent: !!job,
          resolverPresent: !!job && typeof job.resolve === "function",
          taskId: job && job.taskId,
          source: job && job.source,
        });
        assert.ok(job, "job must exist before chrome.tabs.update");
        assert.strictEqual(typeof job.resolve, "function", "resolver must exist before chrome.tabs.update");
        tabs.get(tabId).url = details.url;
        job.noResults = true;
        job.done = true;
        job.resolve({ noResults: true, captured: 0 });
        return { ...tabs.get(tabId) };
      },
      remove: async (tabId) => { tabs.delete(tabId); },
      get: async (tabId) => {
        if (!tabs.has(tabId)) throw new Error("No such tab");
        return { ...tabs.get(tabId) };
      },
      sendMessage: async () => ({}),
    },
    windows: { update: async () => ({}) },
    debugger: {
      onEvent: browserEvent,
      attach: async () => {},
      detach: async () => {},
      sendCommand: async () => ({}),
    },
    alarms: { onAlarm: browserEvent, clear: async () => {}, create: async () => {} },
    notifications: { create: async () => {} },
    scripting: { executeScript: async () => [{ result: true }] },
  };

  const nativeSetTimeout = setTimeout;
  context = vm.createContext({
    chrome,
    console: { log() {}, warn() {}, error() {} },
    fetch: async () => { throw new Error("Unexpected network request"); },
    crypto: globalThis.crypto,
    URL,
    setTimeout: (callback, timeout, ...args) => Number(timeout) >= 100000
      ? { longTimer: true }
      : nativeSetTimeout(callback, 0, ...args),
    clearTimeout() {},
    setInterval: () => ({ interval: true }),
    clearInterval() {},
  });
  vm.runInContext(source, context, { filename: "background.js" });

  context.testItem = {
    task_id: 901,
    claim_token: "claim-runtime-test",
    subject_id: 77,
    name: "Test Sp. z o.o.",
    query: "1234567890",
    query_key: "krs",
    search_kind: "business",
    krs: "0000123456",
    nip: "",
    regon: "",
    pesel: "",
    seen: [],
  };

  const result = await vm.runInContext(
    'processItemInTab(SOURCES.KRZ, testItem, "https://portal-pub-prod.apps.ocp.prod.ms.gov.pl/")',
    context
  );
  const update = events.find((event) => event.step === "update");

  assert.strictEqual(events[0].step, "create");
  assert.strictEqual(events[0].url, "chrome-extension://duir-runtime-test/staging.html");
  assert.ok(update, "chrome.tabs.update must be called");
  assert.strictEqual(update.jobPresent, true);
  assert.strictEqual(update.resolverPresent, true);
  assert.strictEqual(update.taskId, 901);
  assert.strictEqual(update.source, "KRZ");
  assert.strictEqual(result.noResults, true);
}

async function testContentRetriesReadyAndRunsJobOnce() {
  const source = fs.readFileSync(path.join(extensionDir, "content_krz.js"), "utf8");
  const listeners = {};
  const elements = new Map();
  const jobDoneMessages = [];
  let readyCalls = 0;
  let emptyReadyReplies = 0;
  let beforeUnloadHandlers = 0;
  let clock = 1000;
  let accelerateClock = false;

  const job = { taskId: 501, subjectId: 77, query: "6821752158" };
  const document = {
    readyState: "loading",
    body: {
      innerText: "Portal KRZ Wyszukiwanie Podmiotów. ".repeat(20),
      appendChild(element) { if (element.id) elements.set(element.id, element); },
    },
    getElementById: (id) => elements.get(id) || null,
    createElement: () => ({
      id: "",
      textContent: "",
      title: "",
      disabled: false,
      style: {},
      addEventListener() {},
      remove() {},
    }),
    querySelectorAll: () => [],
    querySelector: () => null,
    addEventListener: (type, listener) => { listeners[`document:${type}`] = listener; },
  };
  const window = {
    onbeforeunload: null,
    addEventListener(type, listener) {
      if (type === "beforeunload") {
        beforeUnloadHandlers++;
        accelerateClock = true;
      } else {
        listeners[type] = listener;
      }
    },
  };
  window.top = window;
  window.parent = window;

  const location = {
    href: "https://portal-pub-prod.apps.ocp.prod.ms.gov.pl/#!/application/KRZPortalPUB/1.9/KrzRejPubGui.WyszukiwaniePodmiotow?params=test",
    origin: "https://portal-pub-prod.apps.ocp.prod.ms.gov.pl",
    pathname: "/",
  };
  const chrome = {
    runtime: {
      sendMessage(message, callback) {
        if (message.type === "krzReady") {
          readyCalls++;
          const response = readyCalls <= 4
            ? (emptyReadyReplies++, {})
            : { job };
          setImmediate(() => callback(response));
          return;
        }
        if (message.type === "krzJobDone") jobDoneMessages.push(message);
        setImmediate(() => callback({ ok: true }));
      },
      getManifest: () => ({ version: "runtime-test" }),
    },
  };
  const MockDate = {
    now: () => accelerateClock ? (clock += 200000) : (clock += 1),
  };
  const context = vm.createContext({
    chrome,
    document,
    window,
    location,
    Date: MockDate,
    Number,
    Promise,
    console: { log() {}, warn() {}, error() {} },
    getComputedStyle: () => ({ visibility: "visible", display: "block" }),
    setTimeout: (callback) => setImmediate(callback),
    clearTimeout() {},
    setInterval: () => ({ interval: true }),
    clearInterval() {},
  });
  vm.runInContext(source, context, { filename: "content_krz.js" });

  assert.strictEqual(typeof listeners.DOMContentLoaded, "function", "content script must register init");
  await Promise.all([
    listeners.DOMContentLoaded(),
    listeners.DOMContentLoaded(),
    listeners.DOMContentLoaded(),
  ]);

  assert.ok(readyCalls > 4, "content script must retry krzReady after empty replies");
  assert.strictEqual(emptyReadyReplies, 4);
  assert.strictEqual(beforeUnloadHandlers, 1, "runJob must start exactly once");
  assert.strictEqual(jobDoneMessages.length, 1, "only one runJob completion is allowed");
  assert.strictEqual(jobDoneMessages[0].type, "krzJobDone");
}

(async () => {
  const tests = [
    ["background registers job before navigation", testBackgroundRegistersJobBeforeNavigation],
    ["content retries ready and runs job once", testContentRetriesReadyAndRunsJobOnce],
  ];
  for (const [name, test] of tests) {
    await test();
    process.stdout.write(`PASS ${name}\n`);
  }
  process.stdout.write(`${tests.length} extension runtime tests passed\n`);
})().catch((error) => {
  console.error(error && error.stack ? error.stack : error);
  process.exitCode = 1;
});
