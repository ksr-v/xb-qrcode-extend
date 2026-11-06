#!/usr/bin/env python3
"""Build the plugin runtime ZIP and its file/checksum manifests reproducibly."""
import argparse
import datetime as dt
import hashlib
import json
from pathlib import Path
import re
import stat
import zipfile


def require(condition, message):
    if not condition:
        raise RuntimeError(message)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--timestamp', required=True,
                        help='Fixed ZIP timestamp, for example 2026-11-07T00:00:00Z')
    args = parser.parse_args()
    root = Path(__file__).resolve().parents[1]
    source = root / 'Qrcodeextend'
    stamp = dt.datetime.fromisoformat(args.timestamp.replace('Z', '+00:00'))
    require(stamp.tzinfo is not None, 'Use an explicit timezone in --timestamp')
    stamp = stamp.astimezone(dt.timezone.utc).replace(tzinfo=None)
    require(1980 <= stamp.year <= 2107, 'ZIP timestamp is outside its supported range')
    date_time = (stamp.year, stamp.month, stamp.day, stamp.hour, stamp.minute, stamp.second)
    version = json.loads((source / 'config.json').read_text(encoding='utf-8'))['version']
    require(re.fullmatch(r'\d+\.\d+\.\d+', version), 'Invalid plugin version')
    files, directories = {}, {'Qrcodeextend/'}
    for path in sorted(source.rglob('*')):
        require(not path.is_symlink(), 'Symlink in runtime source')
        name = path.relative_to(root).as_posix()
        require(not any(part.startswith('.') or part in {'node_modules', '__pycache__', 'tests'}
                        for part in path.relative_to(source).parts),
                'Private or development path in runtime source')
        if path.is_dir():
            directories.add(name + '/')
            continue
        require(path.is_file(), 'Non-regular runtime source')
        data = path.read_bytes()
        data.decode('utf-8')
        require(b'\r' not in data, 'Runtime source must use LF: ' + name)
        require(not re.search(rb'-----BEGIN (?:[A-Z ]+ )?PRIVATE KEY-----', data),
                'Private key material in runtime source')
        files[name] = data
    require(len(files) == 12, 'Expected the complete twelve-file plugin runtime')
    output = root / 'dist'
    output.mkdir(exist_ok=True)
    archive = output / f'qrcodeextend-{version}.zip'
    with zipfile.ZipFile(archive, 'w', compression=zipfile.ZIP_DEFLATED, compresslevel=9) as package:
        for name in sorted(directories):
            info = zipfile.ZipInfo(name, date_time)
            info.create_system = 3
            info.external_attr = ((stat.S_IFDIR | 0o755) << 16) | 0x10
            package.writestr(info, b'')
        for name, data in sorted(files.items()):
            info = zipfile.ZipInfo(name, date_time)
            info.create_system = 3
            info.external_attr = (stat.S_IFREG | 0o644) << 16
            info.compress_type = zipfile.ZIP_DEFLATED
            package.writestr(info, data, compresslevel=9)
    digest = hashlib.sha256(archive.read_bytes()).hexdigest()
    manifest = {'archive_sha256': digest, 'canonical_line_endings': 'LF',
                'files': {name: {'sha256': hashlib.sha256(data).hexdigest(), 'size': len(data)}
                          for name, data in sorted(files.items())}, 'version': version}
    manifest_path = output / f'qrcodeextend-{version}.files.json'
    manifest_path.write_text(json.dumps(manifest, indent=2) + '\n', encoding='utf-8', newline='\n')
    sums = f'{digest}  {archive.name}\n{hashlib.sha256(manifest_path.read_bytes()).hexdigest()}  {manifest_path.name}\n'
    (output / 'SHA256SUMS').write_text(sums, encoding='utf-8', newline='\n')
    with zipfile.ZipFile(archive) as package:
        require(package.testzip() is None, 'ZIP CRC verification failed')
        require(set(package.namelist()) == set(files) | directories, 'ZIP member set differs')
        for entry in package.infolist():
            require(entry.create_system == 3, 'ZIP member lacks Unix metadata')
            mode = entry.external_attr >> 16
            require(mode == ((stat.S_IFDIR | 0o755) if entry.is_dir() else (stat.S_IFREG | 0o644)),
                    'Unexpected ZIP member permissions')
            if not entry.is_dir():
                require(package.read(entry) == files[entry.filename], 'ZIP runtime bytes differ')
    print(json.dumps({'version': version, 'archive': str(archive), 'sha256': digest,
                      'bytes': archive.stat().st_size, 'files': len(files),
                      'explicit_root': True, 'unix_permissions_verified': True,
                      'timestamp': stamp.isoformat() + 'Z'}, indent=2))


if __name__ == '__main__':
    main()
