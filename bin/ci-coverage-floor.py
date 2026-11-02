#!/usr/bin/env python3
"""Global line-coverage floor per test suite.

Usage:
  bin/ci-coverage-floor.py check <floor.json> <suite>=<report.xml>... [--measurement <out.json>]
  bin/ci-coverage-floor.py raise <floor.json> <measurement.json>...

check reads one report per suite named in the floor file (Clover for PHPUnit, Cobertura for Jest),
computes covered lines / executable lines over the whole report, and fails when a suite measures
below its floor minus the tolerance. Percentages are truncated to two decimals before they are
compared, so the printed figure is the one that decided the result. A suite whose floor is null
has none yet: it fails and prints the measured figure, which is committed as its first floor.

raise lifts each floor to the lowest of the given measurements when every one of them is above it.
A floor never moves down. The workflow passes the measurements of the latest consecutive runs on
main, see .github/workflows/coverage-floor.yml.

Reports are untrusted input: a report that is missing, malformed, declares entities, or has no
executable lines fails the check instead of counting as covered.
"""

import json
import os
import sys
import xml.etree.ElementTree as ET
from decimal import ROUND_DOWN, Decimal, InvalidOperation
from pathlib import Path

CENT = Decimal('0.01')
HUNDRED = Decimal(100)
FORMATS = ('clover', 'cobertura')


class CoverageError(Exception):
    pass


def truncate(value: Decimal) -> Decimal:
    return value.quantize(CENT, rounding=ROUND_DOWN)


def percentage(covered: int, valid: int) -> Decimal:
    return truncate(Decimal(covered) * HUNDRED / Decimal(valid))


def _count(element: ET.Element, attribute: str, report: str) -> int:
    raw = element.get(attribute)
    if raw is None or not (raw.isascii() and raw.isdigit()):
        raise CoverageError(f'{report}: <{element.tag}> has no non-negative integer {attribute}.')
    return int(raw)


def _parse(path: str) -> ET.Element:
    try:
        content = Path(path).read_bytes()
    except OSError as error:
        raise CoverageError(f'{path}: cannot read the report ({error.strerror}).') from error

    # Coverage tools never declare entities. Refusing them rules out entity expansion attacks
    # regardless of the expat version the runner ships.
    if b'<!ENTITY' in content.upper():
        raise CoverageError(f'{path}: the report declares XML entities.')

    try:
        return ET.fromstring(content)
    except ET.ParseError as error:
        raise CoverageError(f'{path}: malformed XML ({error}).') from error


def read_lines(path: str, report_format: str) -> tuple[int, int]:
    """Returns (covered, executable) line counts of the whole report."""
    root = _parse(path)
    if root.tag != 'coverage':
        raise CoverageError(f'{path}: root element is <{root.tag}>, not <coverage>.')

    if report_format == 'clover':
        # PHPUnit writes one <project> whose direct <metrics> child totals every file. Its
        # statements are executable lines; elements would also count methods and conditionals.
        projects = root.findall('project')
        metrics = projects[0].findall('metrics') if len(projects) == 1 else []
        if len(metrics) != 1:
            raise CoverageError(f'{path}: not a Clover report with one <project><metrics>.')
        covered = _count(metrics[0], 'coveredstatements', path)
        valid = _count(metrics[0], 'statements', path)
    elif report_format == 'cobertura':
        # The root carries exact line counts; its line-rate is rounded and is not used.
        covered = _count(root, 'lines-covered', path)
        valid = _count(root, 'lines-valid', path)
    else:
        raise CoverageError(f'unknown report format {report_format}.')

    if valid == 0:
        raise CoverageError(f'{path}: the report has no executable lines.')
    if covered > valid:
        raise CoverageError(f'{path}: {covered} covered lines exceed {valid} executable lines.')

    return covered, valid


def _decimal(value: object, name: str) -> Decimal:
    if isinstance(value, bool) or not isinstance(value, (int, Decimal)):
        raise CoverageError(f'{name} must be a number.')
    number = Decimal(value)
    if not Decimal(0) <= number <= HUNDRED:
        raise CoverageError(f'{name} must be between 0 and 100.')
    return number


def load_config(path: str) -> dict:
    try:
        config = json.loads(Path(path).read_text(), parse_float=Decimal)
    except OSError as error:
        raise CoverageError(f'{path}: cannot read the floor file ({error.strerror}).') from error
    except json.JSONDecodeError as error:
        raise CoverageError(f'{path}: malformed JSON ({error}).') from error

    suites = config.get('suites') if isinstance(config, dict) else None
    if not isinstance(suites, dict) or not suites:
        raise CoverageError(f'{path}: needs a non-empty "suites" object.')

    _decimal(config.get('tolerance'), 'tolerance')
    for name, suite in suites.items():
        if not isinstance(suite, dict) or suite.get('format') not in FORMATS:
            raise CoverageError(f'{path}: suite {name} needs a format of {", ".join(FORMATS)}.')
        # null: no floor established yet. check fails and prints the figure to commit.
        if 'floor' not in suite:
            raise CoverageError(f'{path}: suite {name} needs a floor (a number, or null).')
        if suite['floor'] is not None:
            _decimal(suite['floor'], f'suites.{name}.floor')

    return config


def check(config: dict, reports: dict[str, str]) -> tuple[bool, list[dict]]:
    suites = config['suites']
    if set(reports) != set(suites):
        raise CoverageError(
            f'expected exactly one report for each suite ({", ".join(sorted(suites))}), '
            f'got {", ".join(sorted(reports)) or "none"}.'
        )

    tolerance = Decimal(config['tolerance'])
    results = []
    for name in suites:
        covered, valid = read_lines(reports[name], suites[name]['format'])
        measured = percentage(covered, valid)
        floor = None if suites[name]['floor'] is None else Decimal(suites[name]['floor'])
        results.append({
            'suite': name,
            'covered': covered,
            'valid': valid,
            'measured': measured,
            'floor': floor,
            'tolerance': tolerance,
            'delta': None if floor is None else measured - floor,
            'passed': floor is not None and measured >= floor - tolerance,
        })

    return all(result['passed'] for result in results), results


def summary(results: list[dict]) -> str:
    rows = [
        '### Coverage floor',
        '',
        '| Suite | Lines | Measured | Floor | Tolerance | Delta | Result |',
        '| --- | --- | --- | --- | --- | --- | --- |',
    ]
    for r in results:
        if r['floor'] is None:
            floor, delta, result = 'none', 'n/a', f"FAIL: commit {r['measured']} as the floor"
        else:
            floor, delta = f"{r['floor']}%", f"{r['delta']:+} pp"
            result = 'pass' if r['passed'] else 'FAIL'
        rows.append(
            f"| {r['suite']} | {r['covered']}/{r['valid']} | {r['measured']}% | {floor} "
            f"| {r['tolerance']} pp | {delta} | {result} |"
        )
    return '\n'.join(rows) + '\n'


def raise_floors(config: dict, measurements: list[dict]) -> list[str]:
    """Raises floors in place; returns one line per raised suite."""
    if not measurements:
        raise CoverageError('raise needs at least one measurement.')

    raised = []
    for name, suite in config['suites'].items():
        # A first floor is committed by hand from a CI run, never proposed by automation.
        if suite['floor'] is None:
            continue

        values = []
        for measurement in measurements:
            if not isinstance(measurement, dict) or not isinstance(measurement.get(name), str):
                raise CoverageError(f'a measurement has no value for suite {name}.')
            try:
                values.append(truncate(_decimal(Decimal(measurement[name]), f'{name} measurement')))
            except InvalidOperation as error:
                raise CoverageError(f'{name} measurement {measurement[name]!r} is not a number.') from error

        floor = Decimal(suite['floor'])
        lowest = min(values)
        if lowest > floor:
            suite['floor'] = lowest
            raised.append(f'{name}: {floor}% -> {lowest}%')

    return raised


def dump(value: object, indent: str = '') -> str:
    """JSON with Decimals written as they read, so a raise rewrites nothing but the floors."""
    inner = indent + '  '
    if isinstance(value, dict):
        if not value:
            return '{}'
        items = [f'{inner}{json.dumps(key)}: {dump(item, inner)}' for key, item in value.items()]
        return '{\n' + ',\n'.join(items) + f'\n{indent}}}'
    if isinstance(value, list):
        if not value:
            return '[]'
        return '[\n' + ',\n'.join(inner + dump(item, inner) for item in value) + f'\n{indent}]'
    if isinstance(value, Decimal):
        return str(value)
    return json.dumps(value)


def _check_command(args: list[str]) -> int:
    measurement_path = None
    if '--measurement' in args:
        position = args.index('--measurement')
        if position + 1 >= len(args):
            raise CoverageError('--measurement needs a path.')
        measurement_path = args[position + 1]
        del args[position:position + 2]

    if len(args) < 2:
        raise CoverageError('usage: check <floor.json> <suite>=<report.xml>...')

    config = load_config(args[0])
    reports = {}
    for argument in args[1:]:
        name, separator, path = argument.partition('=')
        if not separator or not name or not path or name in reports:
            raise CoverageError(f'expected one <suite>=<report.xml> per suite, got {argument!r}.')
        reports[name] = path

    passed, results = check(config, reports)
    text = summary(results)
    print(text)

    summary_file = os.environ.get('GITHUB_STEP_SUMMARY')
    if summary_file:
        with open(summary_file, 'a', encoding='utf-8') as handle:
            handle.write(text + '\n')

    if measurement_path:
        Path(measurement_path).write_text(
            json.dumps({r['suite']: str(r['measured']) for r in results}, indent=2) + '\n'
        )

    if not passed:
        print('FAIL: a suite has no floor, or dropped below its floor minus the tolerance.', file=sys.stderr)
        return 1
    return 0


def _raise_command(args: list[str]) -> int:
    if len(args) < 2:
        raise CoverageError('usage: raise <floor.json> <measurement.json>...')

    config = load_config(args[0])
    measurements = []
    for path in args[1:]:
        try:
            measurements.append(json.loads(Path(path).read_text()))
        except (OSError, json.JSONDecodeError) as error:
            raise CoverageError(f'{path}: unreadable measurement ({error}).') from error

    raised = raise_floors(config, measurements)
    if not raised:
        print(f'No floor raised: not every one of the {len(measurements)} measurements is above it.')
        return 0

    Path(args[0]).write_text(dump(config) + '\n')
    for line in raised:
        print(f'raised {line}')
    return 0


def main(argv: list[str]) -> int:
    commands = {'check': _check_command, 'raise': _raise_command}
    if not argv or argv[0] not in commands:
        print(__doc__.strip(), file=sys.stderr)
        return 2

    try:
        return commands[argv[0]](list(argv[1:]))
    except CoverageError as error:
        print(f'FAIL: {error}', file=sys.stderr)
        return 1


if __name__ == '__main__':
    sys.exit(main(sys.argv[1:]))