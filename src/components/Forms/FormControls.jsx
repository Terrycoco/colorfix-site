
export function Form ({children, width}) {
 return (
  <div width={width} className="grid grid-cols-[auto,1fr] items-center gap-x-4 gap-y-2">
     {children}
  </div>
 );
}


export function FormRow({ label, children }) {
  return (
    <div className="flex items-center gap-4 mb-2">
      <label className="w-24 text-right">{label}</label>
      <div className="flex-1">{children}</div>
    </div>
  );
}

export function TextInput(props) {
  return <input className="w-full px-2 py-1 border rounded" {...props} />;
}

export function CheckboxRow({ options, formData, onChange }) {
  return (
    <div className="w-full flex items-center gap-4">
      {options.map(opt => (
        <label key={opt.name} className="inline-flex items-center gap-1">
          <input
            type="checkbox"
            name={opt.name}
            checked={formData[opt.name]}
            onChange={onChange}
          />
          {opt.label}
        </label>
      ))}
    </div>
  );
}


export function FormInput({ id, name, value, onChange, type = "text", placeholder, className='', ...rest }) {
  return (
    <input
      id={id}
      name={name}
      type={type}
      value={value}
      onChange={onChange}
      placeholder={placeholder}
      className={`w-full px-2 py-1 border rounded text-sm ${className}`}
      {...rest}
    />
  );
}

export function FormRowWithInput({ label, id, name, value, onChange, placeholder, textStyle='', ...rest }) {
  return (
    <FormRow label={label} htmlFor={id}>
      <FormInput
        id={id}
        name={name}
        value={value}
        onChange={onChange}
        placeholder={placeholder}
        className={textStyle}
        {...rest}
      />
    </FormRow>
  );
}


export function FormInlineRow({ label, children }) {
  return (
    <div className="flex items-center gap-4 mb-2 w-full">
      <label className="w-24 text-right shrink-0">{label}</label>
      <div className="flex gap-2 flex-1">
        {children}
      </div>
    </div>
  );
}



// FormCheckbox
export function FormCheckbox({ label, name, checked, onChange }) {
  return (
    <div className="flex items-center gap-2">
      <input
        type="checkbox"
        id={name}
        name={name}
        checked={checked}
        onChange={onChange}
        className="h-4 w-4"
      />
      <label htmlFor={name} className="text-sm">{label}</label>
    </div>
  );
}
