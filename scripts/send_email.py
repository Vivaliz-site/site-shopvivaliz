#!/usr/bin/env python3
import os
import smtplib
import sys
from email.message import EmailMessage
from pathlib import Path
from typing import Optional

DEFAULT_ATTACHMENT_PATHS = [
    Path('planilhas/shopee_import.xlsx')
]


def get_env_variable(name: str) -> str:
    value = os.environ.get(name)
    if not value:
        raise EnvironmentError(f'Missing required environment variable: {name}')
    return value.strip()


def find_attachment() -> Path:
    for path in DEFAULT_ATTACHMENT_PATHS:
        if path.exists():
            return path
    raise FileNotFoundError(
        'No spreadsheet found. Expected one of: ' + ', '.join(str(p) for p in DEFAULT_ATTACHMENT_PATHS)
    )


def build_message(from_addr: str, to_addr: str, attachment_path: Path) -> EmailMessage:
    msg = EmailMessage()
    msg['Subject'] = 'Planilha Shopee Gerada - ShopVivaliz'
    msg['From'] = from_addr
    msg['To'] = to_addr
    msg.set_content('Segue em anexo a planilha pronta para importação na Shopee.')

    with attachment_path.open('rb') as f:
        content = f.read()
    maintype = 'application'
    subtype = 'vnd.openxmlformats-officedocument.spreadsheetml.sheet'
    msg.add_attachment(
        content,
        maintype=maintype,
        subtype=subtype,
        filename=attachment_path.name,
    )
    return msg


def send_email(msg: EmailMessage, host: str, port: int, user: str, password: str) -> None:
    if port == 465:
        server = smtplib.SMTP_SSL(host, port, timeout=30)
    else:
        server = smtplib.SMTP(host, port, timeout=30)
    with server:
        if port != 465:
            server.starttls()
        server.login(user, password)
        server.send_message(msg)


def main(argv=None) -> int:
    try:
        smtp_host = get_env_variable('SMTP_HOST')
        smtp_port = int(get_env_variable('SMTP_PORT'))
        smtp_user = get_env_variable('SMTP_USER')
        smtp_pass = get_env_variable('SMTP_PASS')
        email_from = get_env_variable('EMAIL_FROM')
        email_to = get_env_variable('EMAIL_TO')
    except EnvironmentError as exc:
        print(f'ERROR: {exc}', file=sys.stderr)
        return 1

    try:
        attachment_path = find_attachment()
    except FileNotFoundError as exc:
        print(f'ERROR: {exc}', file=sys.stderr)
        return 1

    print(f'Using attachment: {attachment_path}')
    print(f'Sending email from {email_from} to {email_to} via {smtp_host}:{smtp_port}')

    try:
        message = build_message(email_from, email_to, attachment_path)
        send_email(message, smtp_host, smtp_port, smtp_user, smtp_pass)
        print('Email sent successfully.')
        return 0
    except Exception as exc:
        print(f'ERROR: failed to send email: {exc}', file=sys.stderr)
        return 1


if __name__ == '__main__':
    sys.exit(main())
