#!/usr/bin/env python3
"""Release smoke: the real browse image must start Node and Chromium."""
import ctypes

ctypes.CDLL("libatomic.so.1")

from playwright.sync_api import sync_playwright
from pypdf import PdfReader

with sync_playwright() as playwright:
    browser = playwright.chromium.launch(
        headless=True, args=["--no-sandbox", "--disable-dev-shm-usage"])
    try:
        page = browser.new_page()
        page.set_content("<h1>CoreAI Browse OK</h1>")
        assert page.locator("h1").inner_text() == "CoreAI Browse OK"
        print("PASS: libatomic, Playwright Node, Chromium and pypdf")
    finally:
        browser.close()
