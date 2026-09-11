<?php namespace HumlnetCreative\Pages\Tests;

use PHPUnit\Framework\TestCase;

class RichEditorCountUpIntegrationContractTest extends TestCase
{
    public function testAdapterTargetsTheOctoberFourVueConnectorContract(): void
    {
        $projectRoot = dirname(__DIR__, 4);
        $connector = file_get_contents($projectRoot.'/modules/backend/vuecomponents/richeditordocumentconnector/assets/js/formwidgetconnector.js');
        $adapter = file_get_contents(__DIR__.'/../assets/js/backend-count-up-editor.js');

        $this->assertStringContainsString('toolbarExtensionPoint', $connector);
        $this->assertStringContainsString('getEditor: function getEditor()', $connector);
        $this->assertStringContainsString("internalEventBus.emit('toolbarcmd'", $connector);

        $this->assertStringContainsString("fetchControl(controlElement, 'richeditor')", $adapter);
        $this->assertStringContainsString('control.vueWidget.connectorInstance', $adapter);
        $this->assertStringContainsString('connector.toolbarExtensionPoint', $adapter);
        $this->assertStringContainsString("command: 'insertCountUp'", $adapter);
        $this->assertStringContainsString('[data-cmd="insertCountUp"]', $adapter);
        $this->assertStringNotContainsString('FroalaEditor.INSTANCES', $adapter);
        $this->assertStringNotContainsString('$.FroalaEditor', $adapter);
    }

    public function testBothEditorsAreIdempotentAcrossOctoberAjaxLifecycles(): void
    {
        $richEditor = file_get_contents(__DIR__.'/../assets/js/backend-count-up-editor.js');
        $compactEditor = file_get_contents(__DIR__.'/../formwidgets/countuptext/assets/js/countuptext.js');

        foreach ([$richEditor, $compactEditor] as $adapter) {
            $this->assertStringContainsString("'ajax:update-complete'", $adapter);
            $this->assertStringContainsString("'ajaxUpdateComplete'", $adapter);
            $this->assertStringContainsString('new MutationObserver', $adapter);
        }

        $this->assertStringContainsString('var bindings = new WeakMap()', $richEditor);
        $this->assertStringContainsString("command === 'insertCountUp'", $richEditor);
        $this->assertStringContainsString(':not([data-hucr-initialized])', $compactEditor);
    }
}
