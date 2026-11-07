#!/usr/bin/env python3
"""Organic Code local bootstrap for macOS. Safe by default; no sudo, no remote writes."""
import argparse, json, os, pathlib, platform, shutil, subprocess, sys

ROOT = pathlib.Path(__file__).resolve().parents[1]
HOSTS = ['organiccode.lab','www.organiccode.lab','app.organiccode.lab','cms.organiccode.lab','erp.organiccode.lab','send.organiccode.lab']

def cmd(*args, check=True):
    print('+', ' '.join(map(str,args)))
    return subprocess.run(args, check=check, text=True)

def main():
    p=argparse.ArgumentParser(description='Prepare Organic Code local macOS environment')
    p.add_argument('--apply',action='store_true',help='write project files and create local certificates')
    p.add_argument('--start',action='store_true',help='start Docker Compose (requires --apply)')
    p.add_argument('--install-trust',action='store_true',help='run mkcert -install (may request admin approval)')
    a=p.parse_args()
    if a.start and not a.apply: p.error('--start requires --apply')
    if platform.system()!='Darwin': print('WARNING: designed for macOS')
    print('Project:', ROOT)
    for name in ['docker','mkcert','git','php','composer','claude']:
        print(f'{name}:', shutil.which(name) or 'NOT FOUND')
    print('Local hosts:', ', '.join(HOSTS))
    print('Manual /etc/hosts mapping (requires administrator approval):')
    print('127.0.0.1 ' + ' '.join(HOSTS))
    print('NOTE: This script never edits /etc/hosts or overwrites existing files.')
    if not a.apply:
        print('DRY RUN: use --apply to create missing files and local certificates.')
        return
    if not shutil.which('docker'): sys.exit('Docker is required; install Docker Desktop first.')
    if not shutil.which('mkcert'): sys.exit('mkcert is required; install via brew install mkcert nss')
    certs=ROOT/'.local'/'certs'; certs.mkdir(parents=True,exist_ok=True)
    if a.install_trust: cmd('mkcert','-install')
    crt, key=certs/'organiccode.pem',certs/'organiccode-key.pem'
    if not crt.exists() and not key.exists():
        cmd('mkcert','-cert-file',str(crt),'-key-file',str(key),*HOSTS)
    elif not (crt.exists() and key.exists()):
        sys.exit('Only one certificate file exists; fix manually to avoid overwriting.')
    env=ROOT/'.env'
    if not env.exists():
        env.write_text('APP_NAME="Organic Code"\nAPP_ENV=local\nAPP_DEBUG=true\nAPP_URL=https://www.organiccode.lab\nDB_HOST=db\nDB_PORT=3306\nDB_DATABASE=organiccode\nDB_USERNAME=organiccode\nDB_PASSWORD=CHANGE_ME_BEFORE_START\nDB_ROOT_PASSWORD=CHANGE_ME_BEFORE_START\n',encoding='utf-8')
        print('Created .env template; set strong DB passwords before starting.')
    else: print('Existing .env preserved.')
    if a.start:
        values=dict(line.split('=',1) for line in env.read_text().splitlines() if '=' in line and not line.startswith('#'))
        if any('CHANGE_ME' in values.get(k,'') for k in ['DB_PASSWORD','DB_ROOT_PASSWORD']):
            sys.exit('Set strong DB_PASSWORD and DB_ROOT_PASSWORD in .env before --start')
        cmd('docker','compose','up','-d','--build')
    print('Next: add the hosts line above to /etc/hosts with admin approval; run tests after migrations exist.')
if __name__=='__main__': main()
