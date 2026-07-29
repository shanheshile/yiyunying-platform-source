package xyz.jjmxg.yiyunying.domain.document;

import org.junit.Test;

import static org.junit.Assert.assertEquals;
import static org.junit.Assert.assertFalse;
import static org.junit.Assert.assertTrue;

public final class ShareCodeParserTest {
    @Test public void parsesLabeledChineseCode() {
        assertEquals("Abc_1234-Z", ShareCodeParser.parse("分享码：Abc_1234-Z", false));
    }

    @Test public void parsesApiLink() {
        assertEquals("Fixed_Code-88", ShareCodeParser.parse(
            "http://appht.jjmxg.xyz/api/public/document-shares/Fixed_Code-88", false));
    }

    @Test public void onlyAcceptsPlainCodeOnManualPaste() {
        assertEquals("", ShareCodeParser.parse("Abc_1234-Z", false));
        assertEquals("Abc_1234-Z", ShareCodeParser.parse("Abc_1234-Z", true));
    }

    @Test public void explicitTextDetectionAvoidsOrdinaryClipboardText() {
        assertTrue(ShareCodeParser.isExplicitShareText("分享码：Abc_1234-Z"));
        assertTrue(ShareCodeParser.isExplicitShareText("/document-shares/Abc_1234-Z"));
        assertFalse(ShareCodeParser.isExplicitShareText("这是普通的一段文字"));
    }
}
