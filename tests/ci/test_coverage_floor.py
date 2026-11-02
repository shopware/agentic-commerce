"""Tests for bin/ci-coverage-floor.py. Run: python3 -m unittest discover -s tests/ci"""

import contextlib
import importlib.util
import io
import json
import tempfile
import unittest
import unittest.mock
from decimal import Decimal
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
FIXTURES = Path(__file__).resolve().parent / 'fixtures'

_spec = importlib.util.spec_from_file_location('coverage_floor', ROOT / 'bin' / 'ci-coverage-floor.py')
floor = importlib.util.module_from_spec(_spec)
_spec.loader.exec_module(floor)


def cobertura(covered: int, valid: int) -> str:
    return f'<?xml version="1.0" ?><coverage lines-valid="{valid}" lines-covered="{covered}" line-rate="0"/>'


def clover(covered: int, valid: int) -> str:
    return (
        '<?xml version="1.0"?><coverage><project>'
        '<file name="/a.php"><metrics statements="999" coveredstatements="999"/></file>'
        f'<metrics statements="{valid}" coveredstatements="{covered}" elements="{valid + 50}" coveredelements="0"/>'
        '</project></coverage>'
    )


class CoverageFloorTest(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory()
        self.addCleanup(self.directory.cleanup)
        self.tmp = Path(self.directory.name)

    def write(self, name: str, content: str) -> str:
        path = self.tmp / name
        path.write_text(content)
        return str(path)

    def config(self, php_floor='29.40', admin_floor='29.40') -> dict:
        return json.loads(
            '{"tolerance": 0.1, "suites": {'
            f'"php": {{"format": "clover", "floor": {php_floor}}}, '
            f'"admin": {{"format": "cobertura", "floor": {admin_floor}}}}}}}',
            parse_float=Decimal,
        )

    def run_check(self, covered: int, valid: int, floor_value: str) -> dict:
        config = self.config(php_floor=floor_value, admin_floor=floor_value)
        reports = {
            'php': self.write('clover.xml', clover(covered, valid)),
            'admin': self.write('cobertura.xml', cobertura(covered, valid)),
        }
        passed, results = floor.check(config, reports)
        self.assertEqual({r['passed'] for r in results}, {passed}, 'both formats must agree')
        return results[0]

    def assert_result(self, covered: int, valid: int, floor_value: str, measured: str, passed: bool):
        result = self.run_check(covered, valid, floor_value)
        self.assertEqual(Decimal(measured), result['measured'])
        self.assertIs(passed, result['passed'])

    def test_exact_floor_passes(self):
        self.assert_result(2940, 10000, '29.40', '29.40', True)

    def test_above_floor_passes(self):
        self.assert_result(3000, 10000, '29.40', '30.00', True)

    def test_inside_tolerance_passes(self):
        self.assert_result(2935, 10000, '29.40', '29.35', True)

    def test_tolerance_boundary_passes(self):
        self.assert_result(2930, 10000, '29.40', '29.30', True)

    def test_below_tolerance_fails(self):
        self.assert_result(2929, 10000, '29.40', '29.29', False)

    def test_just_below_boundary_truncates_and_fails(self):
        self.assert_result(292999, 1000000, '29.40', '29.29', False)

    def test_tolerance_is_percentage_points_not_relative(self):
        # 0.1 % of 50 would be 0.05 pp and fail 49.90.
        self.assert_result(4990, 10000, '50.00', '49.90', True)
        self.assert_result(4989, 10000, '50.00', '49.89', False)

    def test_boundary_is_exact_where_floats_are_not(self):
        # 0.7 - 0.1 is 0.6000000000000001 as a float, which would fail an exact 0.60.
        self.assert_result(60, 10000, '0.70', '0.60', True)

    def test_percentage_truncates_instead_of_rounding(self):
        self.assertEqual(Decimal('66.66'), floor.percentage(2, 3))
        self.assertEqual(Decimal('100.00'), floor.percentage(7, 7))
        self.assertEqual(Decimal('0.00'), floor.percentage(0, 7))

    def test_clover_reads_project_statements(self):
        # Written by PHPUnit 10.5 with PCOV: an interface, a class with one uncovered method and a
        # class no test loads, whose two lines count as uncovered.
        self.assertEqual((2, 5), floor.read_lines(str(FIXTURES / 'clover.xml'), 'clover'))

    def test_cobertura_reads_root_line_counts(self):
        self.assertEqual((3, 4), floor.read_lines(str(FIXTURES / 'cobertura.xml'), 'cobertura'))

    def test_clover_ignores_file_metrics_and_elements(self):
        path = self.write('c.xml', clover(10, 40))
        self.assertEqual((10, 40), floor.read_lines(path, 'clover'))

    def test_report_of_the_wrong_format_fails(self):
        with self.assertRaisesRegex(floor.CoverageError, 'not a Clover report'):
            floor.read_lines(str(FIXTURES / 'cobertura.xml'), 'clover')
        with self.assertRaisesRegex(floor.CoverageError, 'lines-covered'):
            floor.read_lines(str(FIXTURES / 'clover.xml'), 'cobertura')

    def test_missing_report_fails(self):
        with self.assertRaisesRegex(floor.CoverageError, 'cannot read'):
            floor.read_lines(str(self.tmp / 'missing.xml'), 'cobertura')

    def test_malformed_xml_fails(self):
        path = self.write('broken.xml', '<coverage lines-valid="1"')
        with self.assertRaisesRegex(floor.CoverageError, 'malformed XML'):
            floor.read_lines(path, 'cobertura')

    def test_empty_file_fails(self):
        with self.assertRaisesRegex(floor.CoverageError, 'malformed XML'):
            floor.read_lines(self.write('empty.xml', ''), 'cobertura')

    def test_zero_executable_lines_fails(self):
        with self.assertRaisesRegex(floor.CoverageError, 'no executable lines'):
            floor.read_lines(self.write('zero.xml', cobertura(0, 0)), 'cobertura')
        with self.assertRaisesRegex(floor.CoverageError, 'no executable lines'):
            floor.read_lines(self.write('zero-clover.xml', clover(0, 0)), 'clover')

    def test_non_integer_or_inconsistent_counts_fail(self):
        for content in (cobertura(-1, 3), '<coverage lines-valid="3" lines-covered="1.5"/>', '<coverage lines-valid="3"/>'):
            with self.subTest(content=content), self.assertRaises(floor.CoverageError):
                floor.read_lines(self.write('bad.xml', content), 'cobertura')
        with self.assertRaisesRegex(floor.CoverageError, 'exceed'):
            floor.read_lines(self.write('over.xml', cobertura(4, 3)), 'cobertura')

    def test_entity_declarations_are_refused(self):
        content = '<?xml version="1.0"?><!DOCTYPE coverage [<!entity a "1">]><coverage lines-valid="1" lines-covered="1"/>'
        with self.assertRaisesRegex(floor.CoverageError, 'entities'):
            floor.read_lines(self.write('entity.xml', content), 'cobertura')

    def test_every_suite_needs_exactly_one_report(self):
        config = self.config()
        report = self.write('c.xml', clover(1, 1))
        with self.assertRaisesRegex(floor.CoverageError, 'exactly one report'):
            floor.check(config, {'php': report})
        with self.assertRaisesRegex(floor.CoverageError, 'exactly one report'):
            floor.check(config, {'php': report, 'admin': report, 'other': report})

    def test_floor_file_is_validated(self):
        cases = {
            'no suites': {'tolerance': 0.1},
            'string floor': {'tolerance': 0.1, 'suites': {'php': {'format': 'clover', 'floor': '29.4'}}},
            'floor over 100': {'tolerance': 0.1, 'suites': {'php': {'format': 'clover', 'floor': 100.5}}},
            'unknown format': {'tolerance': 0.1, 'suites': {'php': {'format': 'lcov', 'floor': 1}}},
            'no tolerance': {'suites': {'php': {'format': 'clover', 'floor': 1}}},
            'no floor key': {'tolerance': 0.1, 'suites': {'php': {'format': 'clover'}}},
        }
        for case, content in cases.items():
            with self.subTest(case), self.assertRaises(floor.CoverageError):
                floor.load_config(self.write('floor.json', json.dumps(content)))
        with self.assertRaisesRegex(floor.CoverageError, 'malformed JSON'):
            floor.load_config(self.write('floor.json', '{"suites":'))

    def test_cli_writes_summary_and_measurement(self):
        floor_file = self.write('floor.json', '{"tolerance": 0.1, "suites": {"admin": {"format": "cobertura", "floor": 70.00}}}')
        report = self.write('cobertura.xml', cobertura(7311, 10000))
        summary_file = self.tmp / 'summary.md'
        measurement = self.tmp / 'measurement.json'

        with unittest.mock.patch.dict('os.environ', {'GITHUB_STEP_SUMMARY': str(summary_file)}), \
                contextlib.redirect_stdout(io.StringIO()):
            status = floor.main(['check', floor_file, f'admin={report}', '--measurement', str(measurement)])

        self.assertEqual(0, status)
        self.assertIn('| admin | 7311/10000 | 73.11% | 70.00% | 0.1 pp | +3.11 pp | pass |', summary_file.read_text())
        self.assertEqual({'admin': '73.11'}, json.loads(measurement.read_text()))

    def test_cli_fails_on_regression_and_on_errors(self):
        floor_file = self.write('floor.json', '{"tolerance": 0.1, "suites": {"admin": {"format": "cobertura", "floor": 80}}}')
        report = self.write('cobertura.xml', cobertura(7311, 10000))
        with contextlib.redirect_stdout(io.StringIO()), contextlib.redirect_stderr(io.StringIO()):
            self.assertEqual(1, floor.main(['check', floor_file, f'admin={report}']))
            self.assertEqual(1, floor.main(['check', floor_file, f'admin={self.tmp / "missing.xml"}']))
            self.assertEqual(1, floor.main(['check', floor_file]))
            self.assertEqual(2, floor.main([]))

    def test_suite_without_a_floor_fails_and_prints_the_figure_to_commit(self):
        floor_file = self.write('floor.json', '{"tolerance": 0.1, "suites": {"admin": {"format": "cobertura", "floor": null}}}')
        report = self.write('cobertura.xml', cobertura(7311, 10000))
        measurement = self.tmp / 'measurement.json'
        output = io.StringIO()

        with contextlib.redirect_stdout(output), contextlib.redirect_stderr(io.StringIO()):
            status = floor.main(['check', floor_file, f'admin={report}', '--measurement', str(measurement)])

        self.assertEqual(1, status)
        self.assertIn('| admin | 7311/10000 | 73.11% | none | 0.1 pp | n/a | FAIL: commit 73.11 as the floor |', output.getvalue())
        self.assertEqual({'admin': '73.11'}, json.loads(measurement.read_text()))

    def test_raise_leaves_a_missing_floor_to_a_person(self):
        config = self.config(php_floor='null', admin_floor='70.00')
        raised = floor.raise_floors(config, [{'php': '31.20', 'admin': '71.00'}])
        self.assertEqual(['admin: 70.00% -> 71.00%'], raised)
        self.assertIsNone(config['suites']['php']['floor'])

    def test_raise_moves_floor_up_to_lowest_measurement(self):
        config = self.config(php_floor='29.40', admin_floor='70.00')
        raised = floor.raise_floors(config, [
            {'php': '31.20', 'admin': '73.11'},
            {'php': '30.05', 'admin': '72.90'},
        ])
        self.assertEqual(['php: 29.40% -> 30.05%', 'admin: 70.00% -> 72.90%'], raised)
        self.assertEqual(Decimal('30.05'), config['suites']['php']['floor'])
        self.assertEqual(Decimal('72.90'), config['suites']['admin']['floor'])

    def test_raise_never_lowers_a_floor(self):
        config = self.config(php_floor='29.40', admin_floor='70.00')
        raised = floor.raise_floors(config, [
            {'php': '31.20', 'admin': '73.11'},
            {'php': '29.35', 'admin': '69.00'},
        ])
        self.assertEqual([], raised)
        self.assertEqual(Decimal('29.40'), config['suites']['php']['floor'])
        self.assertEqual(Decimal('70.00'), config['suites']['admin']['floor'])

    def test_raise_needs_every_run_above_the_floor(self):
        config = self.config(php_floor='29.40', admin_floor='70.00')
        raised = floor.raise_floors(config, [{'php': '29.40', 'admin': '71.00'}, {'php': '35', 'admin': '71.00'}])
        self.assertEqual(['admin: 70.00% -> 71.00%'], raised)
        self.assertEqual(Decimal('29.40'), config['suites']['php']['floor'])

    def test_raise_rejects_incomplete_or_invalid_measurements(self):
        for measurements in ([], [{'php': '30'}], [{'php': 'NaN', 'admin': '1'}], [{'php': '101', 'admin': '1'}], [{'php': 30, 'admin': '1'}]):
            with self.subTest(measurements=measurements), self.assertRaises(floor.CoverageError):
                floor.raise_floors(self.config(), measurements)

    def test_raise_cli_keeps_unrelated_fields(self):
        original = (
            '{\n  "tolerance": 0.1,\n  "patchFailUnder": 80,\n  "raiseAfterRuns": 5,\n'
            '  "note": "kept as is",\n  "suites": {\n'
            '    "php": {\n      "format": "clover",\n      "floor": 29.40\n    },\n'
            '    "admin": {\n      "format": "cobertura",\n      "floor": 73.10\n    }\n  }\n}\n'
        )
        floor_file = self.write('floor.json', original)
        measurements = [self.write(f'm{i}.json', json.dumps({'php': '30.00', 'admin': '73.00'})) for i in range(2)]

        with contextlib.redirect_stdout(io.StringIO()):
            self.assertEqual(0, floor.main(['raise', floor_file, *measurements]))

        self.assertEqual(original.replace('29.40', '30.00'), Path(floor_file).read_text())

    def test_raise_cli_leaves_the_file_untouched_without_a_raise(self):
        original = '{"tolerance": 0.1, "suites": {"admin": {"format": "cobertura", "floor": 73.10}}}'
        floor_file = self.write('floor.json', original)
        measurement = self.write('m.json', json.dumps({'admin': '73.00'}))
        with contextlib.redirect_stdout(io.StringIO()):
            self.assertEqual(0, floor.main(['raise', floor_file, measurement]))
        self.assertEqual(original, Path(floor_file).read_text())


if __name__ == '__main__':
    unittest.main()