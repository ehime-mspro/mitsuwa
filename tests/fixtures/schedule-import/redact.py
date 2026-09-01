#!/usr/bin/env python3
"""書き出し xlsx から個人情報を差し替えた固定資産を作る。

使い方:
    python3 tests/fixtures/schedule-import/redact.py <入力.xlsx> <出力.xlsx>

⚠ **Python の zipfile で書き直してはいけない。**
  この xlsx は「ローカルヘッダと中央ディレクトリの csize/usize が 0xFFFFFFFF ＋
  20 byte の ZIP64 extra を持つのに、EOCD は通常形式」という混成で、
  この形が素朴な zip 実装を壊す。zipfile は必要になるまで ZIP64 を使わないので、
  書き直すと壊れ方が消えて固定資産の意味が無くなる（プラン §0.1-3）。
  よって元の構造をバイト単位で再現する。

⚠ 差し替えるのは C 列（担当会社）・D 列（担当者）・A1（自社名）・B2（現場名）・
  F2（住所）・J2（工事/営業担当）だけ。
  A 列（大工程名）・B 列（工程名）・E/H（日付）・K（期間）・L（状態）は**触らない**。
  ここが取込の検証対象そのもの。
"""
import re
import struct
import sys
import zlib

# ---------------------------------------------------------------- zip 入出力

def read_zip(path):
    """中央ディレクトリを正本として、エントリを順に読む。"""
    d = open(path, 'rb').read()
    eocd = d.rfind(b'PK\x05\x06')
    count, = struct.unpack('<H', d[eocd + 10:eocd + 12])
    cd_off, = struct.unpack('<I', d[eocd + 16:eocd + 20])

    entries, off = [], cd_off
    for _ in range(count):
        (sig, vmade, vneed, flags, method, mtime, mdate, crc, csize, usize,
         nlen, elen, clen, disk, iattr, eattr, lho) = struct.unpack('<IHHHHHHIIIHHHHHII', d[off:off + 46])
        assert sig == 0x02014b50, 'central directory signature'
        name = d[off + 46:off + 46 + nlen]
        extra = d[off + 46 + nlen:off + 46 + nlen + elen]

        # 実サイズは ZIP64 extra (header id 0x0001) の中にある
        real_usize, real_csize = usize, csize
        if extra[:2] == b'\x01\x00':
            real_usize, real_csize = struct.unpack('<QQ', extra[4:20])

        # ローカルヘッダを読み飛ばして圧縮データを取る
        lsig, lvneed, lflags, lmethod, lmt, lmd, lcrc, lcs, lus, lnlen, lelen = \
            struct.unpack('<IHHHHHIIIHH', d[lho:lho + 30])
        assert lsig == 0x04034b50, 'local header signature'
        data_at = lho + 30 + lnlen + lelen
        comp = d[data_at:data_at + real_csize]

        entries.append(dict(
            name=name, vmade=vmade, vneed=vneed, flags=flags, method=method,
            mtime=mtime, mdate=mdate, crc=crc, usize=real_usize, csize=real_csize,
            iattr=iattr, eattr=eattr, comp=comp,
        ))
        off += 46 + nlen + elen + clen
    return entries


def zip64_extra(usize, csize):
    """実ファイルと同じ 20 byte の ZIP64 extra（id=0x0001, size=16, usize, csize）。"""
    return struct.pack('<HHQQ', 0x0001, 16, usize, csize)


def write_zip(path, entries):
    """実ファイルと同じレイアウトで書き出す（ZIP64 ローカルヘッダ ＋ 通常 EOCD）。"""
    out, offsets = bytearray(), []
    for e in entries:
        offsets.append(len(out))
        extra = zip64_extra(e['usize'], e['csize'])
        out += struct.pack('<IHHHHHIIIHH', 0x04034b50, e['vneed'], e['flags'],
                           e['method'], e['mtime'], e['mdate'], e['crc'],
                           0xFFFFFFFF, 0xFFFFFFFF, len(e['name']), len(extra))
        out += e['name'] + extra + e['comp']

    cd_off = len(out)
    for e, lho in zip(entries, offsets):
        extra = zip64_extra(e['usize'], e['csize'])
        out += struct.pack('<IHHHHHHIIIHHHHHII', 0x02014b50, e['vmade'], e['vneed'],
                           e['flags'], e['method'], e['mtime'], e['mdate'], e['crc'],
                           0xFFFFFFFF, 0xFFFFFFFF, len(e['name']), len(extra), 0,
                           0, e['iattr'], e['eattr'], lho)
        out += e['name'] + extra
    cd_size = len(out) - cd_off

    n = len(entries)
    out += struct.pack('<IHHHHIIH', 0x06054b50, 0, 0, n, n, cd_size, cd_off, 0)
    open(path, 'wb').write(bytes(out))


# ---------------------------------------------------------------- 差し替え

CELL = re.compile(rb'<c r="([A-Z]+)(\d+)"([^>]*)(?:/>|>(.*?)</c>)', re.S)
VALUE = re.compile(rb'<v[^>]*>(.*?)</v>', re.S)

# 実データに出てこない、明らかに架空と分かる値
SITE_NAME = 'JG見本町3号地 分譲住宅新築工事様邸'
ADDRESS = '愛媛県松山市見本町1丁目1-1、1-2'
OWN_COMPANY = '株式会社サンプル都市開発'
REAL_OWN_COMPANY = '株式会社ミツワ都市開発'


def cell_values(xml):
    """{('A',1): b'値'} を返す。"""
    out = {}
    for m in CELL.finditer(xml):
        inner = m.group(4)
        if not inner:
            continue
        v = VALUE.search(inner)
        if v:
            out[(m.group(1).decode(), int(m.group(2)))] = v.group(1)
    return out


def collect_tokens(sheets):
    """C 列（会社）と D 列（個人）から、`・` で分割した固有名を集める。"""
    companies, persons = [], []
    for xml in sheets:
        vals = cell_values(xml)
        for (col, row), raw in vals.items():
            if row < 4 or col not in ('C', 'D'):
                continue
            for tok in raw.decode('utf-8').split('・'):
                tok = tok.strip()
                if not tok:
                    continue
                (companies if col == 'C' else persons).append(tok)
    # C 側を優先（両方に出る語は会社として扱う）
    seen, comp_u, pers_u = set(), [], []
    for t in companies:
        if t not in seen:
            seen.add(t); comp_u.append(t)
    for t in persons:
        if t not in seen:
            seen.add(t); pers_u.append(t)
    return comp_u, pers_u


def build_mapping(sheets):
    comp, pers = collect_tokens(sheets)
    m = {}
    for i, t in enumerate(comp, 1):
        m[t] = f'協力会社{i:02d}'
    for i, t in enumerate(pers, 1):
        m[t] = f'担当者{i:02d}'
    # ヘッダー側
    m[REAL_OWN_COMPANY] = OWN_COMPANY
    for xml in sheets:
        vals = cell_values(xml)
        if ('B', 2) in vals:
            m[vals[('B', 2)].decode('utf-8')] = SITE_NAME
        if ('F', 2) in vals:
            m[vals[('F', 2)].decode('utf-8')] = ADDRESS
    return m


def redact(xml, mapping):
    """長いものから順に置換する（短い語が長い語の一部を壊さないように）。"""
    for real in sorted(mapping, key=len, reverse=True):
        xml = xml.replace(real.encode('utf-8'), mapping[real].encode('utf-8'))
    return xml


# ---------------------------------------------------------------- main

def main(src, dst):
    entries = read_zip(src)
    by_name = {e['name'].decode(): e for e in entries}
    sheet_names = [n for n in by_name if re.fullmatch(r'xl/worksheets/sheet\d+\.xml', n)]
    sheet_names.sort()

    plain = {n: zlib.decompress(by_name[n]['comp'], -15) for n in sheet_names}
    mapping = build_mapping(list(plain.values()))

    for n in sheet_names:
        new = redact(plain[n], mapping)
        comp = zlib.compressobj(9, zlib.DEFLATED, -15)
        blob = comp.compress(new) + comp.flush()
        e = by_name[n]
        e['comp'], e['usize'], e['csize'], e['crc'] = blob, len(new), len(blob), zlib.crc32(new) & 0xFFFFFFFF

    write_zip(dst, entries)
    print(f'{src} -> {dst}')
    print(f'  差し替えた固有名: {len(mapping)} 語')
    print(f'  シート: {", ".join(sheet_names)}')


if __name__ == '__main__':
    main(sys.argv[1], sys.argv[2])
