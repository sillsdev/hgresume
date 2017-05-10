// -------------------------------------
//   Task: Display Tasks
// gulp -T                 Print the task dependency tree
// gulp --tasks-simple     Print a list of gulp task names
// -------------------------------------

var gulp = require('gulp');
var gutil = require('gulp-util');
var _execute = require('child_process').exec;
var async = require('async');
var _template = require('lodash.template');
var phpunit = require('gulp-phpunit');
var path = require('path');

var execute = function(command, options, callback) {
  if (!options) {
    options = {};
  }

  options.maxBuffer = 1024 * 1000; // byte

  var template = _template(command);
  command = template(options);
  if (!options.silent) {
    gutil.log(gutil.colors.green(command));
  }

  if (!options.dryRun) {
    var process = _execute(command, options, callback || undefined);

    process.stdout.on('data', function (data) {
      gutil.log(data.slice(0, -1)); // remove trailing \n
    });

    process.stderr.on('data', function (data) {
      gutil.log(gutil.colors.yellow(data.slice(0, -1))); // remove trailing \n
    });

  } else {
    callback(null);
  }
};

// region test

// -------------------------------------
//   Task: test-php-run
// -------------------------------------
gulp.task('test-php-run', function() {
  var src = 'test/phpunit.xml';
  var params = require('yargs')
    .option('debug', {
      demand: false,
      describe: 'flag to run phpunit with debug',
      type: 'boolean' })
    .option('coverage', {
      demand: false,
      describe: 'flag to run phpunit with code coverage',
      type: 'boolean' })
    .argv;
  var options = {
    dryRun: false,
    debug: false,
    logJunit: 'PhpUnitTests.xml'
  };
  if (params.debug) {
    options.debug = true;
    delete options.logJunit;
  }
  if (params.coverage) {
    options.coverageHtml = 'test/CodeCoverage';
  }

  gutil.log("##teamcity[importData type='junit' path='PhpUnitTests.xml']");
  return gulp.src(src)
    .pipe(phpunit('src/vendor/bin/phpunit', options));
});
gulp.task('test-php-run').description = 'run hgresume Unit tests';

// endregion test

// region build

// -------------------------------------
//   Task: Build Composer for production
// -------------------------------------
gulp.task('build-composer', function (cb) {
  var options = {
    dryRun: false,
    silent: false,
    cwd: './src'
  };
  execute(
    'composer install --no-dev',
    options,
    cb
  );
});

// -------------------------------------
//   Task: Change Group to www-data
// -------------------------------------
gulp.task('build-changeGroup', function (cb) {
  execute(
    'sudo chgrp -R www-data src',
    null,
    cb
  );
});

gulp.task('build-changeGroup').description =
  'Ensure www-data is the group';

// -------------------------------------
//   Task: Build (General)
// -------------------------------------
gulp.task('build',
  gulp.series(
    'build-composer',
    'build-changeGroup')
);

// -------------------------------------
//   Task: Build Upload to destination
// -------------------------------------
gulp.task('build-upload', function (cb) {
  var params = require('yargs')
    .option('dest', {
      demand: true,
      type: 'string' })
    .option('uploadCredentials', {
      demand: true,
      type: 'string' })
    .option('branch', {
      demand: true,
      type: 'string' })
    .argv;
  var options = {
    dryRun: false,
    silent: false,
    rsh: '--rsh="ssh -v -i ' + params.uploadCredentials + '"',
    src: 'contrib/',
    dest: params.dest
  };

  // Redmine Resumable.
  // Leave api/ directory intact!
  execute(
    'rsync -progzlt --chmod=Dug=rwx,Fug=rw,o-rwx ' +
    '--delete-during --stats --rsync-path="sudo rsync" <%= rsh %> ' +
    '--exclude=api ' +
    '<%= src %> <%= dest %>',
    options,
    cb
  );

  // api/[branch name]
  options.src = 'src/';
  options.dest = path.join(params.dest, 'api', params.branch);
  execute(
    'rsync -progzlt --chmod=Dug=rwx,Fug=rw,o-rwx ' +
    '--delete-during --stats --rsync-path="sudo rsync" <%= rsh %> ' +
    '<%= src %> <%= dest %>',
    options,
    cb
  );
});

// -------------------------------------
//   Task: Build and Upload to destination
// -------------------------------------
gulp.task('build-and-upload',
  gulp.series(
    'build',
    'build-upload'
  )
);

// endregion build

gulp.task('default', gulp.series('test-php-run'));
