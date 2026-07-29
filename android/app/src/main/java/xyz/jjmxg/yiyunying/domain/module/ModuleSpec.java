package xyz.jjmxg.yiyunying.domain.module;

import java.util.ArrayList;
import java.util.Arrays;
import java.util.Collections;
import java.util.List;

import xyz.jjmxg.yiyunying.domain.Role;

public final class ModuleSpec {
    private final String id;
    private final String title;
    private final String group;
    private final Role role;
    private final ScreenType screenType;
    private final String listPath;
    private final String dataKey;
    private final String idKey;
    private final List<String> primaryKeys;
    private final List<String> secondaryKeys;
    private final boolean paged;
    private final String searchParameter;
    private final boolean requiresApp;
    private final ActionSpec createAction;
    private final List<ActionSpec> itemActions;

    private ModuleSpec(Builder builder) {
        id = builder.id;
        title = builder.title;
        group = builder.group;
        role = builder.role;
        screenType = builder.screenType;
        listPath = builder.listPath;
        dataKey = builder.dataKey;
        idKey = builder.idKey;
        primaryKeys = Collections.unmodifiableList(builder.primaryKeys);
        secondaryKeys = Collections.unmodifiableList(builder.secondaryKeys);
        paged = builder.paged;
        searchParameter = builder.searchParameter;
        requiresApp = builder.requiresApp;
        createAction = builder.createAction;
        itemActions = Collections.unmodifiableList(builder.itemActions);
    }

    public static Builder builder(String id, String title, Role role) {
        return new Builder(id, title, role);
    }

    public Builder toBuilder() {
        Builder builder = new Builder(id, title, role)
            .group(group)
            .screen(screenType)
            .path(listPath)
            .dataKey(dataKey)
            .idKey(idKey)
            .primary(primaryKeys.toArray(new String[0]))
            .secondary(secondaryKeys.toArray(new String[0]));
        if (paged) builder.paged();
        if (!searchParameter.isEmpty()) builder.searchable(searchParameter);
        if (requiresApp) builder.requiresApp();
        if (createAction != null) builder.create(createAction);
        for (ActionSpec action : itemActions) builder.action(action);
        return builder;
    }

    public String id() { return id; }
    public String title() { return title; }
    public String group() { return group; }
    public Role role() { return role; }
    public ScreenType screenType() { return screenType; }
    public String listPath() { return listPath; }
    public String dataKey() { return dataKey; }
    public String idKey() { return idKey; }
    public List<String> primaryKeys() { return primaryKeys; }
    public List<String> secondaryKeys() { return secondaryKeys; }
    public boolean paged() { return paged; }
    public String searchParameter() { return searchParameter; }
    public boolean searchable() { return !searchParameter.isEmpty(); }
    public boolean requiresApp() { return requiresApp; }
    public ActionSpec createAction() { return createAction; }
    public List<ActionSpec> itemActions() { return itemActions; }

    public static final class Builder {
        private final String id;
        private final String title;
        private final Role role;
        private String group = "其他";
        private ScreenType screenType = ScreenType.GENERIC_LIST;
        private String listPath = "";
        private String dataKey = "items";
        private String idKey = "id";
        private List<String> primaryKeys = Arrays.asList("name", "title", "account", "id");
        private List<String> secondaryKeys = Arrays.asList("status", "created_at");
        private boolean paged;
        private String searchParameter = "";
        private boolean requiresApp;
        private ActionSpec createAction;
        private final List<ActionSpec> itemActions = new ArrayList<>();

        private Builder(String id, String title, Role role) {
            this.id = id;
            this.title = title;
            this.role = role;
        }

        public Builder group(String value) { group = value; return this; }
        public Builder screen(ScreenType value) { screenType = value; return this; }
        public Builder path(String value) { listPath = value; return this; }
        public Builder dataKey(String value) { dataKey = value; return this; }
        public Builder idKey(String value) { idKey = value; return this; }
        public Builder primary(String... values) { primaryKeys = Arrays.asList(values); return this; }
        public Builder secondary(String... values) { secondaryKeys = Arrays.asList(values); return this; }
        public Builder paged() { paged = true; return this; }
        public Builder searchable(String parameter) { searchParameter = parameter; return this; }
        public Builder requiresApp() { requiresApp = true; return this; }
        public Builder create(ActionSpec value) { createAction = value; return this; }
        public Builder create(ActionSpec.Builder value) { createAction = value.build(); return this; }
        public Builder action(ActionSpec value) { itemActions.add(value); return this; }
        public Builder action(ActionSpec.Builder value) { itemActions.add(value.build()); return this; }
        public ModuleSpec build() { return new ModuleSpec(this); }
    }
}
