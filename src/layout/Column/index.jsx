export default function Column({
  children,
  width = '1/3',
  align = 'left', // 'left' | 'center' | 'right'
  className = '',
}) {
  const widthClass = {
    '1/4': 'md:w-1/4',
    '1/3': 'md:w-1/3',
    '1/2': 'md:w-1/2',
    '2/3': 'md:w-2/3',
    '3/4': 'md:w-3/4',
    full: 'md:w-full',
    auto: 'md:w-auto',
  }[width] || 'md:w-1/3';

  const alignClass = {
    left: 'items-start',
    center: 'items-center',
    right: 'items-end',
  }[align] || 'items-start';

  return (
    <div className={`w-full ${widthClass} flex flex-col ${alignClass} px-2 ${className}`}>
      {children}
    </div>
  );
}
