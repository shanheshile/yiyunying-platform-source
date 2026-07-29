package xyz.jjmxg.yiyunying.domain.module;

public final class FieldSpec {
    private final String key;
    private final String label;
    private final FieldType type;
    private final boolean required;
    private final String defaultValue;

    private FieldSpec(String key, String label, FieldType type, boolean required, String defaultValue) {
        this.key = key;
        this.label = label;
        this.type = type;
        this.required = required;
        this.defaultValue = defaultValue == null ? "" : defaultValue;
    }

    public static FieldSpec of(String key, String label) {
        return new FieldSpec(key, label, FieldType.TEXT, false, "");
    }

    public static FieldSpec required(String key, String label) {
        return new FieldSpec(key, label, FieldType.TEXT, true, "");
    }

    public static FieldSpec typed(String key, String label, FieldType type, boolean required) {
        return new FieldSpec(key, label, type, required, "");
    }

    public FieldSpec withDefault(String value) {
        return new FieldSpec(key, label, type, required, value);
    }

    public String key() {
        return key;
    }

    public String label() {
        return label;
    }

    public FieldType type() {
        return type;
    }

    public boolean required() {
        return required;
    }

    public String defaultValue() {
        return defaultValue;
    }
}
