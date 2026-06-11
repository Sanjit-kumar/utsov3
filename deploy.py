import ftplib, os, sys

HOST = 'utsov.org'
USER = 'utsov@utsov.org'
PASS = 'Durga@ebhs2023'
LOCAL_DIR = r'C:\Users\sanji\OneDrive\Documents\UTSOV3'

HTML_FILES = [
    'index.html',
    'contact.html',
    'artists.html',
    'events.html',
    'philanthropy.html',
    'sponsorship.html',
    'terms.html',
    'privacy.html',
]

IMAGE_FILES = [
    'images/rangoli-spike-ring.svg',
    'images/garland.svg',
    'images/logo_utsov.png',
    'images/logo_icici.png',
    'images/Maa1_nobg_straight_fixed.png',
    'images/maa1_corner.png',
    'images/mobilemaa.png',
]

print(f'Connecting to {HOST}...')
try:
    ftp = ftplib.FTP(HOST, timeout=30)
    ftp.login(USER, PASS)
    print(f'Logged in. PWD: {ftp.pwd()}')

    # Ensure images/ directory exists on server
    try:
        ftp.mkd('images')
        print('Created images/ directory on server')
    except ftplib.error_perm:
        pass  # already exists

    print('\n-- HTML files --')
    for f in HTML_FILES:
        path = os.path.join(LOCAL_DIR, f)
        if not os.path.exists(path):
            print(f'  SKIP (not found): {f}')
            continue
        size = os.path.getsize(path)
        with open(path, 'rb') as fp:
            ftp.storbinary(f'STOR {f}', fp)
        print(f'  OK  {f}  ({size:,} bytes)')

    print('\n-- Image files --')
    for f in IMAGE_FILES:
        path = os.path.join(LOCAL_DIR, f)
        if not os.path.exists(path):
            print(f'  SKIP (not found): {f}')
            continue
        size = os.path.getsize(path)
        with open(path, 'rb') as fp:
            ftp.storbinary(f'STOR {f}', fp)
        print(f'  OK  {f}  ({size:,} bytes)')

    ftp.quit()
    print('\nDone. All files deployed.')
except Exception as e:
    print(f'\nERROR: {e}', file=sys.stderr)
    sys.exit(1)
