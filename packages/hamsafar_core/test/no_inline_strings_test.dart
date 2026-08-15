import 'dart:io';

import 'package:flutter_test/flutter_test.dart';

/// Externalising copy once is easy; keeping it externalised is the hard part.
///
/// This walks the whole workspace — the shared package and all three apps —
/// and fails the moment a Persian string is typed straight into a widget
/// instead of a string table. It is the Dart counterpart of the server-side
/// test that guards the Blade views and the browser scripts.
void main() {
  test('no screen holds an inline Persian string', () {
    // The tables themselves are the exception, and so is the numeral map in
    // Format: those are locale data, not copy.
    const allowed = {'strings_fa.dart', 'strings_en.dart', 'formatters.dart'};

    final workspace = Directory.current.parent.parent;
    final persian = RegExp(r'[؀-ۿ]');
    final offenders = <String>[];

    for (final root in [
      Directory('${workspace.path}/packages/hamsafar_core/lib'),
      Directory('${workspace.path}/apps/passenger/lib'),
      Directory('${workspace.path}/apps/driver/lib'),
      Directory('${workspace.path}/apps/merchant/lib'),
    ]) {
      if (!root.existsSync()) continue;

      for (final entity in root.listSync(recursive: true)) {
        if (entity is! File || !entity.path.endsWith('.dart')) continue;
        if (allowed.contains(entity.uri.pathSegments.last)) continue;

        final lines = entity.readAsLinesSync();

        for (var i = 0; i < lines.length; i++) {
          if (persian.hasMatch(lines[i])) {
            offenders.add('${entity.path.replaceFirst(workspace.path, '')}:${i + 1}');
          }
        }
      }
    }

    expect(
      offenders,
      isEmpty,
      reason: 'Move these into strings_fa/strings_en:\n${offenders.join('\n')}',
    );
  });
}
