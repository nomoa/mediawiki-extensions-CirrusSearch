/*!
 * Grunt file
 *
 * @package CirrusSearch
 */

/*jshint node:true */
module.exports = function ( grunt ) {
	grunt.loadNpmTasks( 'grunt-contrib-jshint' );
	grunt.loadNpmTasks( 'grunt-jsonlint' );
	grunt.loadNpmTasks( 'grunt-banana-checker' );
	grunt.loadNpmTasks( 'grunt-stylelint' );
	grunt.loadNpmTasks( 'grunt-webdriver' );

	var WebdriverIOconfigFile;

	if ( process.env.JENKINS_HOME ) {
		WebdriverIOconfigFile = './tests/integration/config/wdio.conf.jenkins.js';
	} else {
		WebdriverIOconfigFile = './tests/integration/config/wdio.conf.js';
	}

	grunt.initConfig( {
		jshint: {
			options: {
				jshintrc: true
			},
			all: [
				'**/*.js',
				'!node_modules/**',
				'!vendor/**'
			]
		},
		banana: {
			all: [
				'i18n/'
			]
		},
		jsonlint: {
			all: [
				'**/*.json',
				'!node_modules/**',
				'!vendor/**'
			]
		},
		stylelint: {
			all: [
				'**/*.css',
				'**/*.less',
				'!node_modules/**',
				'!tests/browser/articles/**',
				'!vendor/**'
			]
		},
		// Configure WebdriverIO Node task
		webdriver: {
			test: {
				configFile: WebdriverIOconfigFile
			}
		}
	} );

	grunt.registerTask( 'test', [ 'jshint', 'jsonlint', 'banana', 'stylelint' ] );
	grunt.registerTask( 'default', 'test' );
};
