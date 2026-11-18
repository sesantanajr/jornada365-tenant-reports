"""Collect monthly Entra Identity Score observations into a portal history file."""
import argparse
import json
import os
from contextlib import contextmanager
from datetime import datetime, timezone
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile
import time
from urllib.error import HTTPError
from urllib.parse import urlsplit
from urllib.request import build_opener, HTTPRedirectHandler, Request
from uuid import UUID


ENDPOINT = 'https://graph.microsoft.com/beta/directory/recommendations/tenantSecureScores'
FORMAT = 'j365.identity-score.v1'


class NoRedirect(HTTPRedirectHandler):
    def redirect_request(self, *args):
        return None


def graph_observations(tenant):
    az = shutil.which('az')
    if not az:
        raise ValueError('Azure CLI (az) is required.')
    output = subprocess.check_output([az, 'account', 'get-access-token', '--resource-type',
                                      'ms-graph', '--output', 'json', '--only-show-errors'], timeout=90)
    auth = json.loads(output)
    if str(auth.get('tenant', '')).lower() != tenant:
        raise ValueError('Azure CLI is signed in to a different tenant.')
    opener = build_opener(NoRedirect())
    url = ENDPOINT
    seen = set()
    while url:
        parts = urlsplit(url)
        if parts.scheme != 'https' or parts.netloc != 'graph.microsoft.com' or parts.path != urlsplit(ENDPOINT).path or url in seen:
            raise ValueError('Invalid or repeated Microsoft Graph continuation URL.')
        seen.add(url)
        req = Request(url, headers={'Authorization': 'Bearer ' + auth['accessToken'], 'Accept': 'application/json'})
        for attempt in range(3):
            try:
                with opener.open(req, timeout=35) as response:
                    page = json.load(response)
                break
            except HTTPError as exc:
                delay = exc.headers.get('Retry-After', '')
                delay = int(delay) if delay.isdigit() else 2 ** attempt
                if exc.code not in (429, 502, 503, 504) or attempt == 2 or delay > 60:
                    raise RuntimeError(f'Microsoft Graph returned HTTP {exc.code}.') from None
                time.sleep(delay)
        if not isinstance(page.get('value'), list):
            raise ValueError('Microsoft Graph did not return a score collection.')
        for row in page['value']:
            yield {'date': row.get('createDateTime'), 'current': row.get('tenantScore'), 'max': row.get('tenantMaxScore')}
        url = page.get('@odata.nextLink')


def observation(row, now):
    if not isinstance(row, dict) or not isinstance(row.get('date'), str):
        return None
    try:
        stamp = datetime.fromisoformat(row['date'].replace('Z', '+00:00'))
        if stamp.tzinfo is None:
            return None
        stamp = stamp.astimezone(timezone.utc)
    except (ValueError, OverflowError):
        return None
    current, maximum = row.get('current'), row.get('max')
    if type(current) is not int or type(maximum) is not int or not 0 <= current <= maximum or not 0 < maximum < 2**53 or stamp > now:
        return None
    return {'date': stamp.isoformat(timespec='milliseconds').replace('+00:00', 'Z'), 'current': current, 'max': maximum}


@contextmanager
def archive_lock(path):
    # Keep the lock file's inode stable between scheduled invocations.
    with path.with_name(path.name + '.lock').open('a+b') as handle:
        if os.name == 'nt':
            import msvcrt
            handle.seek(0)
            msvcrt.locking(handle.fileno(), msvcrt.LK_NBLCK, 1)
        else:
            import fcntl
            fcntl.flock(handle.fileno(), fcntl.LOCK_EX | fcntl.LOCK_NB)
        yield


def update_archive(path, tenant, incoming, now):
    fresh = [entry for row in incoming if (entry := observation(row, now))]
    if not fresh:
        raise ValueError('Microsoft Graph returned no valid score observations.')
    with archive_lock(path):
        previous = []
        if path.exists():
            archive = json.loads(path.read_text(encoding='utf-8'))
            if archive.get('format') != FORMAT or str(archive.get('tenantId')).lower() != tenant or not isinstance(archive.get('observations'), list):
                raise ValueError('The existing archive has a different tenant or format.')
            for row in archive['observations']:
                entry = observation(row, now)
                if entry is None:
                    raise ValueError('The existing archive contains an invalid observation.')
                previous.append(entry)
        months = {}
        for row in previous + fresh:
            month = row['date'][:7]
            year_num, month_num = map(int, month.split('-'))
            age = (now.year - year_num) * 12 + now.month - month_num
            if 0 <= age < 24 and (month not in months or months[month]['date'] <= row['date']):
                months[month] = row
        if not months or not any(row['date'][:7] in months for row in fresh):
            raise ValueError('No new Graph data falls within the 24-month window.')
        archive = {'format': FORMAT, 'tenantId': tenant, 'observations': [months[m] for m in sorted(months)]}
        tmp = None
        try:
            with tempfile.NamedTemporaryFile('w', dir=path.parent, delete=False, encoding='utf-8') as handle:
                tmp = Path(handle.name)
                json.dump(archive, handle, indent=2)
                handle.write('\n')
                handle.flush()
                os.fsync(handle.fileno())
            os.replace(tmp, path)
        finally:
            if tmp:
                tmp.unlink(missing_ok=True)
        return len(months)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--tenant', required=True, type=UUID, help='Expected Microsoft Entra tenant ID')
    parser.add_argument('--output', required=True, type=Path, help='Private JSON archive path')
    args = parser.parse_args()
    tenant = str(args.tenant)
    try:
        count = update_archive(args.output, tenant, graph_observations(tenant), datetime.now(timezone.utc))
    except (ValueError, KeyError, OSError, RuntimeError, subprocess.SubprocessError) as exc:
        sys.exit(f'Collection failed: {exc}')
    print(f'Archived {count} measured month(s) in {args.output}.')


if __name__ == '__main__':
    main()
