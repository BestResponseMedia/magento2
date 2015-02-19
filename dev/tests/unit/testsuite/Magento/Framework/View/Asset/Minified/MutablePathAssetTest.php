<?php
/**
 * Copyright © 2015 Magento. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Framework\View\Asset\Minified;

class MutablePathAssetTest extends AbstractAssetTestCase
{
    /**
     * @var MutablePathAsset
     */
    protected $_model;

    protected function setUp()
    {
        parent::setUp();

        $this->_model = new MutablePathAsset(
            $this->_asset,
            $this->_logger,
            $this->_filesystem,
            $this->_baseUrl,
            $this->_adapter
        );
    }

    /**
     * @param string $method
     * @param string $expected
     * @dataProvider inMemoryDecoratorDataProvider
     */
    public function testInMemoryDecorator($method, $expected)
    {
        $this->prepareRequestedAsMinifiedMock();
        $this->_adapter->expects($this->never())->method('minify');
        $this->assertSame($expected, $this->_model->$method());
        $this->assertSame($expected, $this->_model->$method()); // invoke second time to test in-memory caching
    }

    /**
     * Prepare case when an asset is requested explicitly with ".min" suffix
     *
     * In this case the minification is not supposed to occur
     */
    private function prepareRequestedAsMinifiedMock()
    {
        $this->_asset->expects($this->any())->method('getPath')->will($this->returnValue('test/admin.min.js'));
        $this->_asset->expects($this->once())->method('getSourceFile')->will($this->returnValue('source_file'));
        $this->_asset->expects($this->once())->method('getFilePath')->will($this->returnValue('file_path'));
        $this->_asset->expects($this->once())->method('getContext')->will($this->returnValue('context'));
        $this->_asset->expects($this->once())->method('getUrl')->will($this->returnValue('url'));
    }

    /**
     * @return array
     */
    public function inMemoryDecoratorDataProvider()
    {
        return [
            ['getUrl', 'url'],
            ['getSourceFile', 'source_file'],
            ['getPath', 'test/admin.min.js'],
            ['getFilePath', 'file_path'],
            ['getContext', 'context'],
        ];
    }

    /**
     * @param string $method
     * @param string $expected
     * @dataProvider assetDecoratorDataProvider
     */
    public function testAssetDecorator($method, $expected)
    {
        $this->_asset->expects($this->exactly(2))->method($method)->will($this->returnValue($expected));
        $this->assertSame($expected, $this->_model->$method());
        $this->assertSame($expected, $this->_model->$method()); // 2 times to ensure asset is invoked every time
    }

    /**
     * @return array
     */
    public function assetDecoratorDataProvider()
    {
        return [
            ['getContentType', 'content_type'],
            ['getModule', 'module'],
        ];
    }

    public function testGetContent()
    {
        $this->prepareRequestedAsMinifiedMock();
        $this->_adapter->expects($this->never())->method('minify');
        $this->_staticViewDir->expects($this->exactly(2))
            ->method('readFile')
            ->with('test/admin.min.js')
            ->will($this->returnValue('content'));
        $this->assertEquals('content', $this->_model->getContent());
        $this->assertEquals('content', $this->_model->getContent());
    }

    public function testHasPreminifiedFile()
    {
        $this->_asset->expects($this->exactly(2))->method('getPath')->will($this->returnValue('test/admin.js'));
        $this->_asset->expects($this->atLeastOnce())
            ->method('getSourceFile')
            ->will($this->returnValue('/foo/bar/test/admin.js'));
        $this->_asset->expects($this->once())->method('getFilePath')->will($this->returnValue('file_path'));
        $this->_asset->expects($this->once())->method('getContext')->will($this->returnValue('context'));
        $this->_asset->expects($this->once())->method('getUrl')->will($this->returnValue('url'));
        $this->_rootDir->expects($this->once())
            ->method('getRelativePath')
            ->with('/foo/bar/test/admin.min.js')
            ->will($this->returnValue('test/admin.min.js'));
        $this->_rootDir->expects($this->once())
            ->method('isExist')
            ->with('test/admin.min.js')
            ->will($this->returnValue(true));
        $this->_adapter->expects($this->never())->method('minify');
        $this->assertEquals('test/admin.min.js', $this->_model->getPath());
    }

    public function testMinify()
    {
        $this->prepareAttemptToMinifyMock(false);
        $this->_asset->expects($this->once())->method('getContent')->will($this->returnValue('content'));
        $this->_adapter->expects($this->once())->method('minify')->with('content')->will($this->returnValue('mini'));
        $this->_staticViewDir->expects($this->once())->method('writeFile')->with($this->anything(), 'mini');
        $this->assertStringMatchesFormat('%s_admin.min.js', $this->_model->getFilePath());
    }

    public function testMinificationFailed()
    {
        $this->prepareAttemptToMinifyMock(false, true, false);
        $this->_asset->expects($this->exactly(2))->method('getContent')->will($this->returnValue('content'));
        $e = new \Exception('test');
        $this->_adapter->expects($this->once())->method('minify')->with('content')->will($this->throwException($e));
        $this->_logger->expects($this->once())->method('critical');
        $this->_staticViewDir->expects($this->once())->method('writeFile');
        $this->_asset->expects($this->once())->method('getFilePath')->will($this->returnValue('file_path'));
        $this->_asset->expects($this->once())->method('getContext')->will($this->returnValue('context'));
        $this->_asset->expects($this->once())->method('getUrl')->will($this->returnValue('url'));
        $this->assertEquals('test/admin.js', $this->_model->getPath());
    }

    public function testShouldNotMinifyCozExists()
    {
        $this->prepareAttemptToMinifyMock(true);
        // IS_EXISTS is assumed by default, so nothing to mock here
        $this->_adapter->expects($this->never())->method('minify');
        $this->assertStringMatchesFormat('%s_admin.min.js', $this->_model->getFilePath());
    }

    /**
     * @param int $mtimeOrig
     * @param int $mtimeMinified
     * @param bool $isMinifyExpected
     * @dataProvider minifyMtimeDataProvider
     */
    public function testMinifyMtime($mtimeOrig, $mtimeMinified, $isMinifyExpected)
    {
        $this->prepareAttemptToMinifyMock(true, false);
        $model = new MutablePathAsset(
            $this->_asset,
            $this->_logger,
            $this->_filesystem,
            $this->_baseUrl,
            $this->_adapter,
            AbstractAsset::MTIME
        );
        $this->_rootDir->expects($this->any())
            ->method('getRelativePath')
            ->will($this->returnValueMap([
                ['/foo/bar/test/admin.min.js', 'test/admin.min.js'],
                ['/foo/bar/test/admin.js', 'test/admin.js'],
            ]));
        $this->_rootDir->expects($this->once())
            ->method('isExist')
            ->with('test/admin.min.js')
            ->will($this->returnValue(false));
        $this->_rootDir->expects($this->once())
            ->method('stat')
            ->with('test/admin.js')
            ->will($this->returnValue(['mtime' => $mtimeOrig]));
        $this->_staticViewDir->expects($this->once())
            ->method('stat')
            ->with($this->anything())
            ->will($this->returnValue(['mtime' => $mtimeMinified]));
        if ($isMinifyExpected) {
            $this->_asset->expects($this->once())->method('getContent')->will($this->returnValue('content'));
            $this->_adapter->expects($this->once())
                ->method('minify')
                ->with('content')
                ->will($this->returnValue('mini'));
            $this->_staticViewDir->expects($this->once())->method('writeFile')->with($this->anything(), 'mini');
        } else {
            $this->_adapter->expects($this->never())->method('minify');
        }
        $this->assertStringMatchesFormat('%s_admin.min.js', $model->getFilePath());
    }

    /**
     * @return array
     */
    public function minifyMtimeDataProvider()
    {
        return [
            [1, 2, true],
            [3, 3, false],
        ];
    }
}
