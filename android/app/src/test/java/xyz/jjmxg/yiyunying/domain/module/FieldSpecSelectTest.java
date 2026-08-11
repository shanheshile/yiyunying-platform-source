package xyz.jjmxg.yiyunying.domain.module;

import org.junit.Test;

import java.util.Arrays;
import java.util.HashSet;
import java.util.Set;

import xyz.jjmxg.yiyunying.domain.Role;

import static org.junit.Assert.assertEquals;
import static org.junit.Assert.assertFalse;
import static org.junit.Assert.assertNotNull;
import static org.junit.Assert.assertTrue;

/** Pure contract for Chinese selector labels without changing wire values. */
public class FieldSpecSelectTest {
    @Test
    public void selectorMapsInternalCodeToChineseLabelAndRejectsFreeText() {
        FieldSpec field = FieldSpec.select("packet_mode", "红包分配方式", true,
            FieldSpec.option("equal", "等额分配"),
            FieldSpec.option("random", "随机分配")
        ).withDefault("random");

        assertEquals(FieldType.SELECT, field.type());
        assertEquals("随机分配", field.optionForValue(field.defaultValue()).label());
        assertEquals("equal", field.optionForValue("等额分配").value());
        assertTrue(field.allowsValue("random"));
        assertTrue(field.allowsValue("随机分配"));
        assertFalse(field.allowsValue("equal/random"));
    }

    @Test
    public void visibleProtocolEnumerationsUseClosedChineseSelectors() {
        Set<String> expected = new HashSet<>(Arrays.asList(
            "target_type", "effect", "reaction_type", "activity_type", "funding_mode", "packet_mode",
            "edition_code", "scope_type", "popup_frequency", "audience_type", "channel_type", "room_kind",
            "join_mode", "card_type", "result_visibility"
        ));
        Set<String> found = new HashSet<>();
        ModuleRegistry registry = new ModuleRegistry();
        for (Role role : Role.values()) {
            for (ModuleSpec module : registry.forRole(role)) {
                assertSelector(module.createAction(), expected, found);
                for (ActionSpec action : module.itemActions()) assertSelector(action, expected, found);
            }
        }
        assertEquals(expected, found);
    }

    private static void assertSelector(ActionSpec action, Set<String> expected, Set<String> found) {
        if (action == null) return;
        for (FieldSpec field : action.fields()) {
            if (!expected.contains(field.key())) continue;
            found.add(field.key());
            assertEquals(field.key() + " must be a closed selector", FieldType.SELECT, field.type());
            assertFalse(field.key() + " must have Chinese label", field.label().matches(".*[A-Za-z].*"));
            assertFalse(field.key() + " requires choices", field.options().isEmpty());
            for (FieldSpec.Option option : field.options()) {
                assertNotNull(option.value());
                assertFalse(option.value().isEmpty());
                assertTrue(option.label() + " must contain Chinese", option.label().matches(".*[\\u4e00-\\u9fff].*"));
            }
        }
    }
}
